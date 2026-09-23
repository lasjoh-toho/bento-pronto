<?php
/**
 * bento-pronto hub configuration — copy this file to config.php (same
 * folder) and edit it. config.php is gitignored on purpose: it holds
 * password hashes and must never be committed.
 *
 * Generate a password hash from the command line:
 *   php -r "echo password_hash('DEIN-PASSWORT', PASSWORD_DEFAULT), \"\n\";"
 */

return [
    // Protects the WHOLE admin area (index.php + presentation.php: creating,
    // uploading, reordering, deleting). Required — the hub refuses to run
    // without one.
    'admin_password_hash' => 'PASTE-A-password_hash()-RESULT-HERE',

    // Optional per-presentation passwords. Keyed by the presentation's slug
    // (the folder name under hub/data/presentations/ — presentation.php
    // shows it once a presentation exists). Omit a tier, or the whole slug,
    // to leave that link open without a password.
    //
    // 'presentations' => [
    //     'mathe-9c-trigonometrie' => [
    //         'public_password_hash' => 'PASTE-A-password_hash()-RESULT-HERE',
    //         'moderator_password_hash' => 'PASTE-A-DIFFERENT-password_hash()-RESULT-HERE',
    //     ],
    // ],
    'presentations' => [],

    // PHP session cookie name for the hub — change only if it clashes with
    // another app on the same domain.
    'session_name' => 'bento_hub',
];
