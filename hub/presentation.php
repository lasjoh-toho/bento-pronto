<?php
/**
 * bento-pronto hub — manage ONE presentation: upload/rename/reorder/delete
 * its modules, set each module's visibility (0 hidden / 1 public / 2
 * moderator-only — see lib.php), and show the three link types the whole
 * hub exists for: one public link, one moderator link, and one direct link
 * per module.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';

bento_hub_require_admin();

$slug = (string) ($_GET['slug'] ?? '');
$dir = bento_hub_presentation_dir($slug);
$meta = $dir !== null ? bento_hub_load_meta($slug) : null;
if ($meta === null) {
    http_response_code(404);
    bento_hub_page_header('Nicht gefunden', 'index.php', 'Übersicht');
    echo '<p class="hub-error">Präsentation nicht gefunden.</p>';
    bento_hub_page_footer();
    exit;
}

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bento_hub_check_csrf();
    $action = (string) ($_POST['hub_action'] ?? '');
    $meta = bento_hub_load_meta($slug) ?? $meta; // reload fresh before mutating

    if ($action === 'rename') {
        $meta['title'] = trim((string) ($_POST['title'] ?? '')) ?: $meta['title'];
        bento_hub_save_meta($slug, $meta);
        $notice = 'Titel gespeichert.';
    }

    if ($action === 'upload') {
        $files = $_FILES['modules'] ?? null;
        $uploaded = 0;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || $name === '') continue;
                $html = file_get_contents($files['tmp_name'][$i]);
                if ($html === false || !bento_hub_looks_like_bento_html($html)) {
                    $error = 'Mindestens eine Datei sah nicht wie eine gültige .bento.html-Datei aus und wurde übersprungen.';
                    continue;
                }
                $filename = bento_hub_unique_module_filename($slug, bento_hub_sanitize_module_filename($name));
                file_put_contents($dir . '/modules/' . $filename, $html);
                $meta['modules'][] = [
                    'file' => $filename,
                    'title' => bento_hub_extract_title($html, $name),
                    'visibility' => BENTO_HUB_VIS_HIDDEN, // new modules start hidden until reviewed and promoted
                ];
                $uploaded++;
            }
        }
        bento_hub_save_meta($slug, $meta);
        $notice = $uploaded > 0 ? ($uploaded . ' Modul(e) hochgeladen — zunächst versteckt, Sichtbarkeit unten setzen.') : $notice;
    }

    if ($action === 'set_visibility') {
        $file = (string) ($_POST['file'] ?? '');
        $vis = (int) ($_POST['visibility'] ?? 0);
        if ($vis >= 0 && $vis <= 2) {
            foreach ($meta['modules'] as &$m) {
                if ($m['file'] === $file) { $m['visibility'] = $vis; break; }
            }
            unset($m);
            bento_hub_save_meta($slug, $meta);
        }
    }

    if ($action === 'rename_module') {
        $file = (string) ($_POST['file'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') {
            foreach ($meta['modules'] as &$m) {
                if ($m['file'] === $file) { $m['title'] = $title; break; }
            }
            unset($m);
            bento_hub_save_meta($slug, $meta);
        }
    }

    if ($action === 'move') {
        $file = (string) ($_POST['file'] ?? '');
        $dir_move = (string) ($_POST['dir'] ?? '');
        $modules = $meta['modules'];
        $idx = null;
        foreach ($modules as $i => $m) { if ($m['file'] === $file) { $idx = $i; break; } }
        if ($idx !== null) {
            $swap = $dir_move === 'up' ? $idx - 1 : $idx + 1;
            if ($swap >= 0 && $swap < count($modules)) {
                [$modules[$idx], $modules[$swap]] = [$modules[$swap], $modules[$idx]];
                $meta['modules'] = $modules;
                bento_hub_save_meta($slug, $meta);
            }
        }
    }

    if ($action === 'delete_module') {
        $file = (string) ($_POST['file'] ?? '');
        $path = bento_hub_module_path($slug, $file);
        if ($path !== null && is_file($path)) unlink($path);
        $meta['modules'] = array_values(array_filter($meta['modules'], fn($m) => $m['file'] !== $file));
        bento_hub_save_meta($slug, $meta);
    }

    header('Location: presentation.php?slug=' . urlencode($slug));
    exit;
}

$modules = $meta['modules'] ?? [];
$passwords = bento_hub_presentation_passwords($slug);
$base = (str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']))) . '/';
$publicUrl = $base . 'play.php?slug=' . urlencode($slug) . '&as=public';
$moderatorUrl = $base . 'play.php?slug=' . urlencode($slug) . '&as=moderator';

bento_hub_page_header($meta['title'], 'index.php', 'Übersicht');
?>
<div class="hub-top">
  <div>
    <h1 class="hub-h1"><?= bento_hub_e($meta['title']) ?></h1>
    <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:6px">
      <input type="hidden" name="hub_action" value="rename">
      <?= bento_hub_csrf_field() ?>
      <input type="text" name="title" value="<?= bento_hub_e($meta['title']) ?>" style="width:280px">
      <button type="submit" class="hub-btn">Titel speichern</button>
    </form>
  </div>
</div>

<?php if ($notice): ?><p class="hub-muted"><?= bento_hub_e($notice) ?></p><?php endif; ?>
<?php if ($error): ?><p class="hub-error"><?= bento_hub_e($error) ?></p><?php endif; ?>

<div class="hub-card">
  <h2 style="margin-top:0;font-size:15px">Links</h2>
  <div class="hub-links">
    <div class="hub-link-row">
      <span class="hub-pill ok">Öffentlich</span>
      <code id="link-public"><?= bento_hub_e($publicUrl) ?></code>
      <button type="button" class="hub-btn" onclick="hubCopy(this, document.getElementById('link-public').textContent)">Kopieren</button>
      <?php if ($passwords['public']): ?><span class="hub-pill">🔒 Passwort</span><?php endif; ?>
    </div>
    <div class="hub-link-row">
      <span class="hub-pill mod">Moderator</span>
      <code id="link-mod"><?= bento_hub_e($moderatorUrl) ?></code>
      <button type="button" class="hub-btn" onclick="hubCopy(this, document.getElementById('link-mod').textContent)">Kopieren</button>
      <?php if ($passwords['moderator']): ?><span class="hub-pill">🔒 Passwort</span><?php endif; ?>
    </div>
  </div>
  <p class="hub-muted" style="margin-bottom:0">Der öffentliche Link zeigt nur <strong>öffentliche</strong> Module, der Moderator-Link zusätzlich die <strong>Nur-Moderator/in</strong>-Module. Passwörter werden in <code>hub/config.php</code> je Präsentation vergeben.</p>
</div>

<div class="hub-card">
  <h2 style="margin-top:0;font-size:15px">Module</h2>
  <?php if (!$modules): ?>
    <p class="hub-muted">Noch keine Module hochgeladen.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>Titel</th><th>Sichtbarkeit</th><th>Link</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($modules as $i => $m):
      $moduleUrl = $base . 'module.php?slug=' . urlencode($slug) . '&file=' . urlencode($m['file']);
    ?>
      <tr>
        <td>
          <form method="post" style="display:flex;gap:6px">
            <input type="hidden" name="hub_action" value="rename_module">
            <input type="hidden" name="file" value="<?= bento_hub_e($m['file']) ?>">
            <?= bento_hub_csrf_field() ?>
            <input type="text" name="title" value="<?= bento_hub_e($m['title'] ?? $m['file']) ?>" style="width:200px" onchange="this.form.submit()">
          </form>
          <div class="hub-muted" style="font-size:11px;margin-top:2px"><?= bento_hub_e($m['file']) ?></div>
        </td>
        <td>
          <form method="post">
            <input type="hidden" name="hub_action" value="set_visibility">
            <input type="hidden" name="file" value="<?= bento_hub_e($m['file']) ?>">
            <?= bento_hub_csrf_field() ?>
            <select name="visibility" onchange="this.form.submit()">
              <option value="0" <?= (int) $m['visibility'] === 0 ? 'selected' : '' ?>>Versteckt</option>
              <option value="1" <?= (int) $m['visibility'] === 1 ? 'selected' : '' ?>>Öffentlich</option>
              <option value="2" <?= (int) $m['visibility'] === 2 ? 'selected' : '' ?>>Nur Moderator/in</option>
            </select>
          </form>
        </td>
        <td>
          <div class="hub-link-row" style="padding:4px 8px">
            <code id="link-m<?= $i ?>" style="font-size:11px"><?= bento_hub_e($moduleUrl) ?></code>
            <button type="button" class="hub-btn" onclick="hubCopy(this, document.getElementById('link-m<?= $i ?>').textContent)">Kopieren</button>
          </div>
        </td>
        <td class="hub-actions">
          <form method="post" class="hub-inline">
            <input type="hidden" name="hub_action" value="move">
            <input type="hidden" name="file" value="<?= bento_hub_e($m['file']) ?>">
            <input type="hidden" name="dir" value="up">
            <?= bento_hub_csrf_field() ?>
            <button type="submit" class="hub-btn" <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
          </form>
          <form method="post" class="hub-inline">
            <input type="hidden" name="hub_action" value="move">
            <input type="hidden" name="file" value="<?= bento_hub_e($m['file']) ?>">
            <input type="hidden" name="dir" value="down">
            <?= bento_hub_csrf_field() ?>
            <button type="submit" class="hub-btn" <?= $i === count($modules) - 1 ? 'disabled' : '' ?>>↓</button>
          </form>
          <form method="post" class="hub-inline" onsubmit="return confirm('Modul löschen?')">
            <input type="hidden" name="hub_action" value="delete_module">
            <input type="hidden" name="file" value="<?= bento_hub_e($m['file']) ?>">
            <?= bento_hub_csrf_field() ?>
            <button type="submit" class="hub-btn hub-danger">Löschen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="hub-card">
  <h2 style="margin-top:0;font-size:15px">Module hochladen</h2>
  <p class="hub-muted">Fertige <code>.bento.html</code>-Dateien — aus dem Bento-Editor gespeichert, oder mit dem <a href="../template.php">Konverter</a> erzeugt. Neue Module starten versteckt.</p>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="hub_action" value="upload">
    <?= bento_hub_csrf_field() ?>
    <input type="file" name="modules[]" accept=".html,.htm" multiple required>
    <button type="submit" class="hub-btn hub-primary">Hochladen</button>
  </form>
</div>
<?php
bento_hub_page_footer();
