<?php
/**
 * bento-pronto hub — shared helpers for the presentation hub (index.php,
 * presentation.php, play.php, module.php). One admin (this project is a
 * single-person tool, not multi-tenant) organizes any number of
 * presentations, each built from one or more standalone `.bento.html`
 * modules (produced by the Bento editor's own Save, or by this project's
 * own converter). Storage is plain files: one folder per presentation
 * under data/presentations/<slug>/, a meta.json describing title + module
 * order + visibility, and the module files themselves. No database.
 *
 * Visibility is the same three-state model mod_bento's bento_decks table
 * uses (see moodle-mod_bento/db/install.xml): 0 = hidden (draft, admin-only
 * preview), 1 = public (shown on the public link), 2 = moderator-only
 * (teacher/speaker notes-style slides — shown on the moderator link, never
 * the public one). Deliberately kept in sync with that terminology so
 * anyone who's used the Moodle plugin already knows this model.
 */

declare(strict_types=1);

const BENTO_HUB_VIS_HIDDEN = 0;
const BENTO_HUB_VIS_PUBLIC = 1;
const BENTO_HUB_VIS_MODERATOR = 2;

function bento_hub_config(): array {
    static $config = null;
    if ($config !== null) return $config;
    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        http_response_code(500);
        die('hub/config.php fehlt. Kopiere hub/config.example.php nach hub/config.php und setze mindestens ein Admin-Passwort.');
    }
    $loaded = require $path;
    $config = (is_array($loaded) ? $loaded : []) + [
        'admin_password_hash' => null,
        'presentations' => [],
        'session_name' => 'bento_hub',
    ];
    return $config;
}

function bento_hub_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(bento_hub_config()['session_name']);
    session_start();
}

function bento_hub_e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// ---- storage ----

function bento_hub_data_dir(): string {
    $dir = __DIR__ . '/data/presentations';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

function bento_hub_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? $s : 'praesentation';
}

function bento_hub_unique_slug(string $base): string {
    $dir = bento_hub_data_dir();
    $slug = $base;
    $i = 2;
    while (is_dir($dir . '/' . $slug)) {
        $slug = $base . '-' . $i;
        $i++;
    }
    return $slug;
}

/** @return string[] slugs of every presentation, alphabetically */
function bento_hub_list_presentations(): array {
    $dir = bento_hub_data_dir();
    $slugs = [];
    foreach ((scandir($dir) ?: []) as $entry) {
        if ($entry === '' || $entry[0] === '.') continue;
        if (is_dir($dir . '/' . $entry) && is_file($dir . '/' . $entry . '/meta.json')) {
            $slugs[] = $entry;
        }
    }
    sort($slugs);
    return $slugs;
}

/** Slug -> path, with traversal guarded: only ever a direct child of data/presentations. */
function bento_hub_presentation_dir(string $slug): ?string {
    if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) return null;
    return bento_hub_data_dir() . '/' . $slug;
}

function bento_hub_load_meta(string $slug): ?array {
    $dir = bento_hub_presentation_dir($slug);
    if ($dir === null) return null;
    $path = $dir . '/meta.json';
    if (!is_file($path)) return null;
    $meta = json_decode((string) file_get_contents($path), true);
    if (!is_array($meta)) return null;
    return $meta + ['title' => $slug, 'modules' => [], 'created' => null, 'modified' => null];
}

function bento_hub_save_meta(string $slug, array $meta): void {
    $dir = bento_hub_presentation_dir($slug);
    if ($dir === null) return;
    $meta['modified'] = date('c');
    file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function bento_hub_create_presentation(string $title): string {
    $slug = bento_hub_unique_slug(bento_hub_slugify($title !== '' ? $title : 'praesentation'));
    $dir = bento_hub_presentation_dir($slug);
    mkdir($dir . '/modules', 0775, true);
    bento_hub_save_meta($slug, [
        'title' => $title !== '' ? $title : $slug,
        'created' => date('c'),
        'modules' => [],
    ]);
    return $slug;
}

/** Deletes a presentation folder and everything in it. Irreversible — caller confirms first. */
function bento_hub_delete_presentation(string $slug): void {
    $dir = bento_hub_presentation_dir($slug);
    if ($dir === null || !is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Sanitizes an uploaded filename down to a safe `<slug>.bento.html` module filename. */
function bento_hub_sanitize_module_filename(string $name): string {
    $base = preg_replace('/\.bento\.html$/i', '', $name) ?? $name;
    $base = preg_replace('/\.[a-z0-9]+$/i', '', $base) ?? $base; // drop any other trailing extension too
    $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?? '';
    $base = trim($base, '-');
    if ($base === '') $base = 'modul';
    return $base . '.bento.html';
}

function bento_hub_unique_module_filename(string $slug, string $wanted): string {
    $dir = bento_hub_presentation_dir($slug) . '/modules';
    $stem = preg_replace('/\.bento\.html$/i', '', $wanted);
    $filename = $wanted;
    $i = 2;
    while (is_file($dir . '/' . $filename)) {
        $filename = $stem . '-' . $i . '.bento.html';
        $i++;
    }
    return $filename;
}

function bento_hub_module_path(string $slug, string $file): ?string {
    $dir = bento_hub_presentation_dir($slug);
    if ($dir === null || $file === '' || !preg_match('/^[a-zA-Z0-9_-]+\.bento\.html$/', $file)) return null;
    return $dir . '/modules/' . $file;
}

/** Rejects anything that isn't a genuine spliced Bento document. */
function bento_hub_looks_like_bento_html(string $html): bool {
    return (bool) preg_match('/<script[^>]*id=["\']bento-doc["\'][^>]*>/', $html);
}

/** Best-effort title straight from the embedded #bento-doc JSON; falls back to the filename. */
function bento_hub_extract_title(string $html, string $fallback): string {
    if (preg_match('/<script[^>]*id=["\']bento-doc["\'][^>]*>([\s\S]*?)<\/script>/', $html, $m)) {
        $doc = json_decode(trim($m[1]), true);
        if (is_array($doc) && !empty($doc['title'])) return (string) $doc['title'];
    }
    return $fallback;
}

/** Raw #bento-doc JSON string from a module's HTML, or null if none is found. */
function bento_hub_extract_doc_json(string $html): ?string {
    if (preg_match('/<script[^>]*id=["\']bento-doc["\'][^>]*>([\s\S]*?)<\/script>/', $html, $m)) {
        $json = trim($m[1]);
        return json_decode($json) !== null ? $json : null;
    }
    return null;
}

function bento_hub_visibility_label(int $v): string {
    return match ($v) {
        BENTO_HUB_VIS_PUBLIC => 'Öffentlich',
        BENTO_HUB_VIS_MODERATOR => 'Nur Moderator/in',
        default => 'Versteckt',
    };
}

// ---- admin auth (protects index.php + presentation.php: create/upload/reorder/delete) ----

function bento_hub_is_admin(): bool {
    bento_hub_start_session();
    return !empty($_SESSION['hub_admin']);
}

function bento_hub_csrf_field(): string {
    bento_hub_start_session();
    if (empty($_SESSION['hub_csrf'])) $_SESSION['hub_csrf'] = bin2hex(random_bytes(24));
    return '<input type="hidden" name="hub_csrf" value="' . bento_hub_e($_SESSION['hub_csrf']) . '">';
}

function bento_hub_check_csrf(): void {
    bento_hub_start_session();
    $token = (string) ($_POST['hub_csrf'] ?? '');
    if ($token === '' || empty($_SESSION['hub_csrf']) || !hash_equals($_SESSION['hub_csrf'], $token)) {
        http_response_code(400);
        die('Ungültige Anfrage (CSRF-Token fehlt/abgelaufen) — Seite neu laden und erneut versuchen.');
    }
}

/** Call at the top of index.php/presentation.php: shows the login form and exits if not authenticated. */
function bento_hub_require_admin(): void {
    if (bento_hub_is_admin()) return;
    $error = '';
    if (($_POST['hub_action'] ?? '') === 'login') {
        $hash = bento_hub_config()['admin_password_hash'];
        if ($hash && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            bento_hub_start_session();
            session_regenerate_id(true);
            $_SESSION['hub_admin'] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        $error = 'Falsches Passwort.';
    }
    bento_hub_page_header('Anmelden');
    ?>
    <div class="hub-card hub-narrow">
      <h1 class="hub-h1">Präsentationszentrale</h1>
      <?php if ($error): ?><p class="hub-error"><?= bento_hub_e($error) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="hub_action" value="login">
        <label class="hub-field">Admin-Passwort
          <input type="password" name="password" autofocus required>
        </label>
        <button type="submit" class="hub-btn hub-primary">Anmelden</button>
      </form>
    </div>
    <?php
    bento_hub_page_footer();
    exit;
}

function bento_hub_logout_and_redirect(): void {
    bento_hub_start_session();
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ---- visitor auth (protects a single presentation's public/moderator play link, only when a password is set) ----

/** @return array{public:?string, moderator:?string} password hashes, or null where no password is configured */
function bento_hub_presentation_passwords(string $slug): array {
    $cfg = bento_hub_config()['presentations'][$slug] ?? [];
    return [
        'public' => $cfg['public_password_hash'] ?? null,
        'moderator' => $cfg['moderator_password_hash'] ?? null,
    ];
}

function bento_hub_visitor_unlocked(string $slug, string $tier): bool {
    if (bento_hub_is_admin()) return true; // the admin never needs a separate visitor password
    if (empty(bento_hub_presentation_passwords($slug)[$tier])) return true; // no password configured for this tier — nothing to unlock
    bento_hub_start_session();
    if (!empty($_SESSION['hub_unlocked'][$slug]['moderator'])) return true; // moderator access implies public access
    return !empty($_SESSION['hub_unlocked'][$slug][$tier]);
}

function bento_hub_visitor_unlock(string $slug, string $tier): void {
    bento_hub_start_session();
    $_SESSION['hub_unlocked'][$slug][$tier] = true;
}

// ---- shared page chrome (same dark/peach palette as template.php, kept minimal) ----

function bento_hub_page_header(string $title, ?string $backHref = null, ?string $backLabel = null): void {
    ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= bento_hub_e($title) ?> — Präsentationszentrale</title>
<style>
  :root{
    --bg:#0D1B2E; --panel:#16273E; --panel-2:#1D3049; --line:rgba(182,193,210,.16);
    --ink:#EDEFF3; --ink-dim:rgba(182,193,210,.72); --accent:#FF9E8A; --accent-dim:#C25A43;
    --good:#6fce8f; --bad:#e2686a; --warn:#e2b568;
    --mono:"SF Mono","JetBrains Mono",Consolas,monospace;
    --sans:"Inter",system-ui,-apple-system,"Segoe UI",sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);padding:32px 20px 80px;line-height:1.5}
  a{color:var(--accent)}
  .hub-wrap{max-width:920px;margin:0 auto}
  .hub-top{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap}
  .hub-h1{font-size:26px;margin:0 0 6px;font-weight:700}
  .hub-back{font-size:13px;color:var(--ink-dim);text-decoration:none}
  .hub-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:22px 24px;margin-bottom:18px}
  .hub-narrow{max-width:360px;margin:60px auto}
  .hub-error{color:var(--bad);font-size:13.5px}
  .hub-field{display:block;font-size:13px;color:var(--ink-dim);margin-bottom:14px}
  input[type=text],input[type=password],input[type=file]{
    width:100%;margin-top:6px;padding:9px 11px;border-radius:8px;border:1px solid var(--line);
    background:var(--panel-2);color:var(--ink);font-family:var(--sans);font-size:14px;
  }
  select{padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink);font-size:13px}
  .hub-btn{font-family:var(--sans);font-size:13px;font-weight:600;cursor:pointer;border-radius:9px;padding:9px 14px;
    border:1px solid var(--line);background:var(--panel-2);color:var(--ink)}
  .hub-btn:hover{border-color:var(--accent)}
  .hub-primary{background:var(--accent);color:#241205;border-color:var(--accent)}
  .hub-primary:hover{background:#ffb27a}
  .hub-danger{color:var(--bad)}
  .hub-danger:hover{border-color:var(--bad)}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  th{text-align:left;color:var(--ink-dim);font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;padding:6px 8px;border-bottom:1px solid var(--line)}
  td{padding:8px;border-bottom:1px solid var(--line);vertical-align:middle}
  tr:last-child td{border-bottom:none}
  .hub-pill{font-family:var(--mono);font-size:10.5px;padding:2px 8px;border-radius:100px;background:var(--panel-2);color:var(--ink-dim);white-space:nowrap}
  .hub-pill.ok{color:var(--good)}
  .hub-pill.mod{color:var(--warn)}
  .hub-links{display:flex;flex-direction:column;gap:8px;margin:14px 0}
  .hub-link-row{display:flex;align-items:center;gap:8px;background:var(--panel-2);border-radius:9px;padding:8px 10px}
  .hub-link-row code{flex:1;font-family:var(--mono);font-size:12px;color:var(--ink-dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .hub-muted{color:var(--ink-dim);font-size:13px}
  form.hub-inline{display:inline}
  .hub-actions{display:flex;gap:8px;align-items:center}
</style>
</head>
<body>
<div class="hub-wrap">
<?php if ($backHref): ?><a class="hub-back" href="<?= bento_hub_e($backHref) ?>">&larr; <?= bento_hub_e($backLabel ?? 'Zurück') ?></a><?php endif; ?>
<?php
}

function bento_hub_page_footer(): void {
?>
</div>
<script>
function hubCopy(btn, text){
  navigator.clipboard.writeText(text).then(()=>{
    const old = btn.textContent; btn.textContent = 'Kopiert!';
    setTimeout(()=>{ btn.textContent = old; }, 1200);
  });
}
</script>
</body>
</html>
<?php
}
