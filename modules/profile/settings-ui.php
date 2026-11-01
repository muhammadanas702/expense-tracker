<?php
/**
 * Settings hub: the sections of Profile & Settings, each on its own page (settings/*.php), plus the shared page frame.
 * To add a section: add it to settings_sections(), create settings/<key>.php with settings_boot() ... settings_page_start().
 */
require_once __DIR__ . '/../../includes/security.php';

/** key => [icon, title, one-line description, file] (order = order on the hub and in the side menu). */
function settings_sections(): array
{
    return [
        'account' => ['usr', 'Account', 'Your name and e-mail', 'settings/account.php'],
        'security' => ['lock', 'Password & security', 'Change your password, see how you sign in', 'settings/security.php'],
        'preferences' => ['set', 'Preferences', 'Savings goal and currency', 'settings/preferences.php'],
        'notifications' => ['bell', 'Notifications', 'Reminders and alerts', 'settings/notifications.php'],
        'apps' => ['phone', 'Apps & devices', 'Install, download and update the app', 'settings/apps.php'],
        'data' => ['db', 'Data & privacy', 'Export, reset your data, delete your account', 'settings/data.php'],
    ];
}

/**
 * Starts a settings page: session, database, sign-in check, the user row. Returns [$user, $user_id].
 * Call before any output; it needs the includes the page layout uses.
 */
function settings_boot(): array
{
    global $conn, $base_url; // declared first: config/db.php (included below) creates them
    secure_session_start();
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../includes/logging.php';
    require_once __DIR__ . '/../../includes/layout.php';
    require_once __DIR__ . '/lib.php';
    require_once __DIR__ . '/../oauth/lib.php';
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . $base_url . '/auth/login.php');
        exit();
    }
    $uid = (int) $_SESSION['user_id'];
    $st = $conn->prepare('SELECT name, email, is_admin FROM users WHERE id = ?');
    $st->execute([$uid]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: ' . $base_url . '/auth/login.php');
        exit();
    }
    return [$user, $uid];
}

/** Message + redirect back to the same page (so a refresh never repeats a POST). */
function settings_done(string $ok = '', string $err = ''): void
{
    if ($ok !== '') {
        $_SESSION['success'] = $ok;
    }
    if ($err !== '') {
        $_SESSION['error'] = $err;
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'profile.php'));
    exit();
}

/** The page frame: header, breadcrumb, side menu (desktop) and an opening content column. */
function settings_page_start(string $key, array $user, string $sub = ''): void
{
    global $base_url;
    $secs = settings_sections();
    [$icon, $title] = $secs[$key];
    ef_page_start(['title' => $title, 'sub' => $sub !== '' ? $sub : $secs[$key][2], 'neutral' => true, 'active' => 'profile', 'user' => $user]);
    ?>
<nav class="crumb" aria-label="Breadcrumb"><a href="<?= e($base_url) ?>/profile.php"><?= ef_icon('chev') ?> Profile &amp; Settings</a><span><?= e($title) ?></span></nav>
<div class="sgrid">
  <aside class="g card snav" aria-label="Settings sections">
<?php foreach ($secs as $k => [$ic, $t, $d, $f]): ?>
    <a class="snl<?= $k === $key ? ' on' : '' ?>" href="<?= e($base_url . '/' . $f) ?>"><?= ef_icon($ic) ?><span><?= e($t) ?></span></a>
<?php endforeach; ?>
  </aside>
  <div class="scol">
<?php
}

function settings_page_end(string $scripts = ''): void
{
    echo "  </div>\n</div>\n";
    ef_page_end(['active' => '', 'scripts' => $scripts]);
}
