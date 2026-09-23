<?php
/**
 * bento-pronto hub — admin overview. Lists every presentation, lets the
 * admin create a new one (a title is all that's needed; modules get added
 * on presentation.php) and delete existing ones. Password-gated by
 * bento_hub_require_admin() — see lib.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';

bento_hub_require_admin(); // exits (renders the login form) if not authenticated

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bento_hub_check_csrf();
    $action = (string) ($_POST['hub_action'] ?? '');

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = bento_hub_create_presentation($title);
        header('Location: presentation.php?slug=' . urlencode($slug));
        exit;
    }

    if ($action === 'delete') {
        $slug = (string) ($_POST['slug'] ?? '');
        if (bento_hub_presentation_dir($slug) !== null) {
            bento_hub_delete_presentation($slug);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'logout') {
        bento_hub_logout_and_redirect();
    }
}

$slugs = bento_hub_list_presentations();

bento_hub_page_header('Präsentationen');
?>
<div class="hub-top">
  <div>
    <h1 class="hub-h1">Präsentationszentrale</h1>
    <p class="hub-muted">Jede Präsentation besteht aus mehreren Modulen (fertigen <code>.bento.html</code>-Dateien) mit eigener Sichtbarkeit.</p>
  </div>
  <form method="post"><?= bento_hub_csrf_field() ?><input type="hidden" name="hub_action" value="logout">
    <button type="submit" class="hub-btn">Abmelden</button>
  </form>
</div>

<div class="hub-card">
  <h2 style="margin-top:0;font-size:15px">Neue Präsentation</h2>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="hub_action" value="create">
    <?= bento_hub_csrf_field() ?>
    <label class="hub-field" style="flex:1;min-width:220px;margin:0">Titel
      <input type="text" name="title" placeholder="z. B. Mathe 9c — Trigonometrie" required autofocus>
    </label>
    <button type="submit" class="hub-btn hub-primary">Anlegen</button>
  </form>
</div>

<?php if (!$slugs): ?>
  <p class="hub-muted">Noch keine Präsentation angelegt.</p>
<?php else: ?>
  <div class="hub-card">
    <table>
      <thead><tr><th>Titel</th><th>Module</th><th>Geändert</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($slugs as $slug):
        $meta = bento_hub_load_meta($slug);
        if ($meta === null) continue;
        $modules = $meta['modules'] ?? [];
        $publicCount = count(array_filter($modules, fn($m) => (int) ($m['visibility'] ?? 0) === BENTO_HUB_VIS_PUBLIC));
        $modCount = count(array_filter($modules, fn($m) => (int) ($m['visibility'] ?? 0) === BENTO_HUB_VIS_MODERATOR));
      ?>
        <tr>
          <td><a href="presentation.php?slug=<?= urlencode($slug) ?>"><strong><?= bento_hub_e($meta['title']) ?></strong></a></td>
          <td>
            <span class="hub-pill"><?= count($modules) ?> gesamt</span>
            <span class="hub-pill ok"><?= $publicCount ?> öffentlich</span>
            <span class="hub-pill mod"><?= $modCount ?> Moderator</span>
          </td>
          <td class="hub-muted"><?= bento_hub_e($meta['modified'] ? substr((string) $meta['modified'], 0, 16) : '—') ?></td>
          <td class="hub-actions">
            <a class="hub-btn" href="presentation.php?slug=<?= urlencode($slug) ?>">Verwalten</a>
            <form method="post" class="hub-inline" onsubmit="return confirm('„<?= bento_hub_e($meta['title']) ?>“ inklusive aller Module endgültig löschen?')">
              <input type="hidden" name="hub_action" value="delete">
              <input type="hidden" name="slug" value="<?= bento_hub_e($slug) ?>">
              <?= bento_hub_csrf_field() ?>
              <button type="submit" class="hub-btn hub-danger">Löschen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
bento_hub_page_footer();
