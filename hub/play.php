<?php
/**
 * bento-pronto hub — audience-facing landing page for ONE presentation at
 * ONE tier (?as=public or ?as=moderator). No admin login required; gated
 * only by that presentation's own optional password (hub/config.php,
 * per-slug — see lib.php). Lists the modules visible at this tier, each
 * linking straight into module.php (which re-checks visibility itself,
 * so this page is a convenience index, not the actual access boundary).
 *
 * There is deliberately no seamless cross-module "next slide" auto-advance
 * here: each module is its own standalone .bento.html file with its own
 * embedded Bento app, and Bento's present-mode playlist hook
 * (editor/moodle.ts's MoodleConfig.playlist) only activates inside a real
 * mod/bento Moodle URL — wiring an equivalent for arbitrary standalone
 * files is a Bento-core (kernel-zone) change, not something this
 * standalone PHP tool can add on its own. Until then: this page IS the
 * navigation between modules — presenters click through it, or use the
 * back button, between slides.
 */

declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$slug = (string) ($_GET['slug'] ?? '');
$tier = (string) ($_GET['as'] ?? 'public');
if (!in_array($tier, ['public', 'moderator'], true)) $tier = 'public';

$meta = bento_hub_load_meta($slug);
if ($meta === null) {
    http_response_code(404);
    bento_hub_page_header('Nicht gefunden');
    echo '<p class="hub-error">Präsentation nicht gefunden.</p>';
    bento_hub_page_footer();
    exit;
}

$passwords = bento_hub_presentation_passwords($slug);
$neededHash = $passwords[$tier] ?? null;
$unlocked = bento_hub_visitor_unlocked($slug, $tier);

$loginError = '';
if (!$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['hub_action'] ?? '') === 'unlock') {
    if ($neededHash && password_verify((string) ($_POST['password'] ?? ''), $neededHash)) {
        bento_hub_visitor_unlock($slug, $tier);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $loginError = 'Falsches Passwort.';
}
$unlocked = bento_hub_visitor_unlocked($slug, $tier); // re-check after a possible unlock above

bento_hub_page_header($meta['title']);

if ($neededHash && !$unlocked) {
    ?>
    <div class="hub-card hub-narrow">
      <h1 class="hub-h1"><?= bento_hub_e($meta['title']) ?></h1>
      <?php if ($loginError): ?><p class="hub-error"><?= bento_hub_e($loginError) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="hub_action" value="unlock">
        <label class="hub-field">Passwort
          <input type="password" name="password" autofocus required>
        </label>
        <button type="submit" class="hub-btn hub-primary">Ansehen</button>
      </form>
    </div>
    <?php
    bento_hub_page_footer();
    exit;
}

$allowed = $tier === 'moderator' ? [BENTO_HUB_VIS_PUBLIC, BENTO_HUB_VIS_MODERATOR] : [BENTO_HUB_VIS_PUBLIC];
$modules = array_values(array_filter($meta['modules'] ?? [], fn($m) => in_array((int) ($m['visibility'] ?? 0), $allowed, true)));
?>
<h1 class="hub-h1"><?= bento_hub_e($meta['title']) ?></h1>
<?php if ($tier === 'moderator'): ?><p class="hub-pill mod">Moderator-Ansicht</p><?php endif; ?>

<?php if (!$modules): ?>
  <p class="hub-muted">Für diese Ansicht ist aktuell kein Modul freigegeben.</p>
<?php else: ?>
  <div class="hub-card">
    <table>
      <thead><tr><th>#</th><th>Titel</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($modules as $i => $m): ?>
        <tr>
          <td class="hub-muted"><?= $i + 1 ?></td>
          <td>
            <?= bento_hub_e($m['title'] ?? $m['file']) ?>
            <?php if ((int) $m['visibility'] === BENTO_HUB_VIS_MODERATOR): ?><span class="hub-pill mod">Moderator</span><?php endif; ?>
          </td>
          <td><a class="hub-btn hub-primary" href="module.php?slug=<?= urlencode($slug) ?>&file=<?= urlencode($m['file']) ?>&as=<?= urlencode($tier) ?>" target="_blank" rel="noopener">Präsentation starten</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php
bento_hub_page_footer();
