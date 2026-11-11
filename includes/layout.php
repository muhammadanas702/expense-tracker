<?php
/**
 * Shared page shell for every signed-in page (sidebar on desktop, bottom bar
 * on phones, top bar with the Personal / Shared switch).
 *
 * Usage:
 *   ef_page_start(['title'=>'Dashboard','sub'=>'Overview','mode'=>'p','active'=>'dash','user'=>$userRow]);
 *   ... page content ...
 *   ef_page_end();
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/modules.php';

function ef_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $s .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $s !== '' ? $s : '?';
}

function ef_page_start(array $o): void
{
    // Neutral pages (Profile, Notifications, Help) belong to neither Personal nor Shared: no mode switch, and the menu
    // keeps showing whichever side the person was last on.
    $neutral = !empty($o['neutral']);
    if ($neutral) {
        $mode = (($_SESSION['ef_mode'] ?? 'p') === 's' && ef_mode_enabled('s')) ? 's' : 'p';
    } else {
        $mode = ($o['mode'] ?? 'p') === 's' && ef_mode_enabled('s') ? 's' : 'p';
        $_SESSION['ef_mode'] = $mode;
    }
    $active = (string) ($o['active'] ?? '');
    $GLOBALS['__ef_page'] = ['mode' => $mode, 'active' => $active, 'neutral' => $neutral]; // ef_page_end() uses the same values
    $title = (string) ($o['title'] ?? 'ExpenseFlow');
    $sub = (string) ($o['sub'] ?? '');
    $user = $o['user'] ?? [];
    $name = (string) ($user['name'] ?? '');
    $nav = ef_nav($mode);
    $bottom = array_values(array_filter($nav, fn($n) => $n[3] !== 'sadd'));
    $home = ef_nav('p')[0][0] ?? 'dashboard.php';
    $sharedHome = ef_nav('s')[0][0] ?? null;

    // Notification bell: only when the optional Activity module is installed and enabled.
    $unread = null;
    if (isset(ef_modules()['activity']) && isset($_SESSION['user_id'])) {
        global $conn;
        require_once __DIR__ . '/../modules/activity/lib.php';
        $unread = $conn instanceof PDO ? activity_unread_count($conn, (int) $_SESSION['user_id']) : null;
        // Replies from support count too.
        if ($unread !== null && ef_feature_on('support') && is_file(__DIR__ . '/../modules/support/lib.php')) {
            require_once __DIR__ . '/../modules/support/lib.php';
            $unread += support_unseen_count($conn, (int) $_SESSION['user_id']);
        }
    }

    send_security_headers();
    ?><!DOCTYPE html>
<html<?= ef_html_attrs() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(t($title)) ?> · ExpenseFlow</title>
<meta name="theme-color" content="#0B2545">
<link rel="manifest" href="<?= e(ef_url('manifest.json')) ?>">
<?php require_once __DIR__ . '/favicons.php'; ?>
<link rel="stylesheet" href="<?= e(ef_asset('assets/app.css')) ?>">
<?php require_once __DIR__ . '/theme.php'; echo ef_theme_head(); ?>
<?= $o['head'] ?? '' ?>
</head>
<body>
<div class="fr" data-mode="<?= e($mode) ?>">
<div class="ap">
  <aside class="g sb">
    <div class="logo"><img src="<?= e(ef_url('favicon.png')) ?>" alt=""><span class="wm">ExpenseFlow</span></div>
    <div class="sl"><?= e(t($mode === 's' ? 'Shared' : 'Personal')) ?></div>
    <?php foreach ($nav as $n): ?>
      <a class="nv<?= $n[3] === $active ? ' on' : '' ?>" href="<?= e(ef_url($n[0])) ?>"><?= ef_icon($n[1]) ?><?= e(t($n[2])) ?></a>
    <?php endforeach; ?>
    <div class="sp"></div>
    <?php if (!empty($user['is_admin'])): ?><a class="nv" href="<?= e(ef_url('admin/index.php')) ?>"><?= ef_icon('shield') ?><?= e(t('Admin')) ?></a><?php endif; ?>
    <a class="nv<?= $active === 'profile' ? ' on' : '' ?>" href="<?= e(ef_url('profile.php')) ?>"><?= ef_icon('usr') ?><?= e(t('Profile')) ?></a>
    <a class="nv" href="<?= e(ef_url('auth/logout.php')) ?>" data-post data-csrf="<?= e(csrf_token()) ?>"><?= ef_icon('out') ?><?= e(t('Logout')) ?></a>
  </aside>
  <div class="mn">
    <div class="g tb">
      <div class="tt"><h1><?= e(t($title)) ?></h1><?php if ($sub !== ''): ?><small><?= e(t($sub)) ?></small><?php endif; ?></div>
      <?php if ($sharedHome && !$neutral): ?>
      <div class="seg">
        <a class="<?= $mode === 'p' ? 'on' : '' ?>" href="<?= e(ef_url($home)) ?>"><?= ef_icon('wal') ?> <?= e(t('Personal')) ?></a>
        <a class="<?= $mode === 's' ? 'on' : '' ?>" href="<?= e(ef_url($sharedHome)) ?>"><?= ef_icon('users') ?> <?= e(t('Shared')) ?></a>
      </div>
      <?php endif; ?>
      <?php if ($unread !== null): ?>
      <a class="ic bl<?= $active === 'notif' ? ' on' : '' ?>" href="<?= e(ef_url('activity/notifications.php')) ?>" data-pop="<?= e(ef_url('activity/notifications.php?popup=1')) ?>" aria-haspopup="true" aria-expanded="false" aria-label="Notifications<?= $unread ? ' (' . $unread . ' new)' : '' ?>"><?= ef_icon('bell') ?><?php if ($unread > 0): ?><i class="dt"><?= $unread > 99 ? '99+' : $unread ?></i><?php endif; ?></a>
      <?php endif; ?>
      <a class="me" href="<?= e(ef_url('profile.php')) ?>"><i class="av"><?= e(ef_initials($name)) ?></i><span><?= e($name) ?></span></a>
    </div>
    <div class="view">
<?php
    foreach (['success' => 'ok', 'error' => 'err'] as $key => $cls) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            echo '<div class="msg ' . $cls . '" role="status" data-flash>' . e($_SESSION[$key]) . '</div>';
        }
        unset($_SESSION[$key]);
    }
    // Staff announcements (optional module, best-effort).
    if (isset($_SESSION['user_id']) && ef_feature_on('announce') && is_file(__DIR__ . '/../modules/announce/lib.php')) {
        global $conn;
        if ($conn instanceof PDO) {
            require_once __DIR__ . '/../modules/announce/lib.php';
            echo announce_banner_html($conn, (int) $_SESSION['user_id'], csrf_token(), ef_url('announce/dismiss.php'));
        }
    }
}

function ef_page_end(array $o = []): void
{
    $pg = $GLOBALS['__ef_page'] ?? ['mode' => ($o['mode'] ?? 'p') === 's' && ef_mode_enabled('s') ? 's' : 'p', 'active' => (string) ($o['active'] ?? ''), 'neutral' => false];
    $mode = $pg['mode'];
    $active = $pg['active'];
    $bottom = array_values(array_filter(ef_nav($mode), fn($n) => $n[3] !== 'sadd'));
    ?>
    </div>
  </div>
</div>
<?php if ($mode === 's' && !$pg['neutral'] && ef_nav('s')): ?>
<a class="fabf" href="<?= e(ef_url('shared/add.php')) ?>" aria-label="<?= e(t('Add shared expense')) ?>"><?= ef_icon('pl') ?></a>
<?php endif; ?>
<?php
// Phone bottom bar: at most 4 main items (entries flagged 'bar' in module.php, else the first 4) and a "More" sheet for the rest.
$barItems = $bottom;
$moreItems = [];
if (count($bottom) > 5) {
    $flagged = array_values(array_filter($bottom, fn($n) => !empty($n[4])));
    $barItems = array_slice($flagged ?: $bottom, 0, 4);
    $moreItems = array_values(array_filter($bottom, fn($n) => !in_array($n, $barItems, true)));
}
$moreActive = false;
foreach ($moreItems as $n) { if ($n[3] === $active) { $moreActive = true; } }
?>
<?php if ($moreItems): ?>
<div class="g msheet" id="more-sheet" hidden role="menu" aria-label="More">
<?php foreach ($moreItems as $n): ?>
  <a class="<?= $n[3] === $active ? 'on' : '' ?>" role="menuitem" href="<?= e(ef_url($n[0])) ?>"><?= ef_icon($n[1]) ?><span><?= e(t($n[2])) ?></span></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<nav class="g bn">
<?php foreach ($barItems as $n): ?>
  <a class="<?= $n[3] === $active ? 'on' : '' ?>" href="<?= e(ef_url($n[0])) ?>"><?= ef_icon($n[1]) ?><?= e(t(str_replace(['Add ', 'Full '], '', $n[2]))) ?></a>
<?php endforeach; ?>
<?php if ($moreItems): ?>
  <button type="button" class="mbtn<?= $moreActive ? ' on' : '' ?>" id="more-btn" aria-haspopup="true" aria-expanded="false" aria-controls="more-sheet"><?= ef_icon('menu') ?><?= e(t('More')) ?></button>
<?php endif; ?>
</nav>
</div>
<script src="<?= e(ef_asset('assets/client-time.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/fx.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/notif-pop.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/more-menu.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/net-status.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/offline-queue.js')) ?>" defer></script>
<?php if (isset(ef_modules()['quickadd'])): ?>
<script src="<?= e(ef_asset('assets/sms-auto.js')) ?>" data-base="<?= e($GLOBALS['base_url'] ?? '') ?>" defer></script>
<?php endif; ?>
<script src="<?= e(ef_url('assets/pwa-register.js')) ?>" data-app-base="<?= e(rtrim((string) ($GLOBALS['base_url'] ?? ''), '/')) ?>" defer></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}
