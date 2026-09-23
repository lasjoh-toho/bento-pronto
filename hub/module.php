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
 * included inside another page; this always the top-level response.
 * Mirrors moodle-mod_bento's deck.php in spirit (same visibility check,
 * same "not a general read-any-content endpoint" reasoning) even though
 * the transport differs (deck.php returns raw JSON to an already-loaded
 * app; this returns a complete standalone HTML file since that's the
 * whole storage unit here).
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

// Auto-launch present mode, same trick view.php uses in moodle-mod_bento
// (main.ts checks location.hash === '#present' once its bundle runs) —
// injected right after <head> so it runs before the (much larger) app
// bundle further down even parses.
$bootstrap = '<script>location.hash = "present";</script>';
$html = preg_replace('/<head[^>]*>/', '$0' . $bootstrap, $html, 1) ?? $html;

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
echo $html;
