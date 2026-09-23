<?php
/**
 * bento-pronto hub — serves ONE module's raw .bento.html, gated by its own
 * visibility (0 hidden / 1 public / 2 moderator-only) crossed with the
 * caller's own access: an authenticated admin gets everything (this is
 * also how "preview a hidden module" works — presentation.php's per-module
 * link just points here); anyone else needs ?as=public or ?as=moderator
 * AND to already have that presentation's tier unlocked (bento_hub_
 * visitor_unlocked() — either no password was configured, or they passed
 * play.php's password gate this session).
 *
 * Deliberately its own plain GET endpoint, not routed through play.php's
 * page chrome — a module is a full standalone HTML document (its own
 * <html>/<head>/<body>, the whole embedded Bento app), so it can't be
 * included inside another page; this always the top-level response
 * (except ?raw=1, see below).
 *
 * Two extra query params, both opt-in and both still going through the
 * exact same authorization check above:
 *
 * - ?raw=1 — returns just the module's #bento-doc JSON (not the wrapped
 *   HTML page). Mirrors moodle-mod_bento's own deck.php, which serves the
 *   same shape for the same reason: this is what Bento's present-mode
 *   auto-advance fetches (see bento's editor/playlist.ts — a generic,
 *   non-Moodle-specific `<meta name="bento-playlist">` opt-in) once it
 *   reaches the end of the PREVIOUS module in a chain.
 * - ?chain=1 — only meaningful on the normal (non-raw) HTML response:
 *   injects that same <meta name="bento-playlist"> tag, listing every
 *   OTHER module visible at this tier (in stored order) as ?raw=1 URLs.
 *   play.php's "Ganze Präsentation starten" button is the only place that
 *   sets this — a module's own standalone/direct link never does, so
 *   sharing ONE module link keeps behaving like sharing just that one
 *   module, not the whole presentation.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$slug = (string) ($_GET['slug'] ?? '');
$file = (string) ($_GET['file'] ?? '');
$tier = (string) ($_GET['as'] ?? 'public');
if (!in_array($tier, ['public', 'moderator'], true)) $tier = 'public';

$meta = bento_hub_load_meta($slug);
$path = bento_hub_module_path($slug, $file);
if ($meta === null || $path === null || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Modul nicht gefunden.';
    exit;
}

$module = null;
foreach ($meta['modules'] ?? [] as $m) {
    if ($m['file'] === $file) { $module = $m; break; }
}
if ($module === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Modul nicht gefunden.';
    exit;
}

$visibility = (int) ($module['visibility'] ?? BENTO_HUB_VIS_HIDDEN);
$isAdmin = bento_hub_is_admin();
$allowedForTier = $tier === 'moderator' ? [BENTO_HUB_VIS_PUBLIC, BENTO_HUB_VIS_MODERATOR] : [BENTO_HUB_VIS_PUBLIC];
$authorized = $isAdmin
    || (in_array($visibility, $allowedForTier, true) && bento_hub_visitor_unlocked($slug, $tier));

if (!$authorized) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kein Zugriff — falscher Link, falsche Ansicht, oder das Modul ist (noch) nicht freigegeben.';
    exit;
}

$html = file_get_contents($path);
if ($html === false) {
    http_response_code(500);
    exit;
}

$raw = (string) ($_GET['raw'] ?? '') === '1';

if ($raw) {
    $json = bento_hub_extract_doc_json($html);
    if ($json === null) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Modul enthält kein gültiges bento/slides-Dokument.';
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    echo $json;
    exit;
}

$headInject = '<script>location.hash = "present";</script>';

if ((string) ($_GET['chain'] ?? '') === '1') {
    $base = (str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']))) . '/';
    $items = [];
    foreach ($meta['modules'] ?? [] as $m) {
        if ($m['file'] === $file) continue; // never chain into itself
        if (!in_array((int) ($m['visibility'] ?? 0), $allowedForTier, true)) continue;
        $items[] = ['url' => $base . 'module.php?slug=' . urlencode($slug) . '&file=' . urlencode($m['file']) . '&as=' . urlencode($tier) . '&raw=1'];
    }
    if ($items) {
        $content = htmlspecialchars(json_encode(['items' => $items], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $headInject .= '<meta name="bento-playlist" content="' . $content . '">';
    }
}

// Auto-launch present mode, same trick view.php uses in moodle-mod_bento
// (main.ts checks location.hash === '#present' once its bundle runs) —
// injected right after <head> so it runs before the (much larger) app
// bundle further down even parses.
$html = preg_replace('/<head[^>]*>/', '$0' . $headInject, $html, 1) ?? $html;

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
