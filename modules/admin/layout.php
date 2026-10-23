<?php
/**
 * Admin page shell: same glass design as the rest of the app, with the staff menu.
 * Menu items only appear when the page file exists AND the role may use it.
 */
require_once __DIR__ . '/../../includes/layout.php';

/** [key, file, icon, label, permission|null] grouped by section. */
function admin_menu(): array
{
    return [
        'Overview' => [['ov', 'admin/index.php', 'grid', 'Dashboard', null]],
        'Users' => [
            ['users', 'admin/users.php', 'users', 'Users', 'view'],
            ['roles', 'admin/roles.php', 'crown', 'Roles & permissions', 'SA'],
        ],
        'Content' => [
            ['sup', 'admin/support.php', 'lifebuoy', 'Support', 'support'],
            ['ann', 'admin/announcements.php', 'send', 'Announcements', 'bc'],
            ['cat', 'admin/categories.php', 'tag', 'Categories', 'cat'],
            ['cur', 'admin/currencies.php', 'globe', 'Currencies', 'cur'],
            ['grp', 'admin/groups.php', 'act', 'Groups', 'grp'],
        ],
        'Security' => [
            ['log', 'admin/audit.php', 'cal', 'Audit log', 'log'],
            ['ulog', 'admin/logs.php', 'doc', 'User logs', 'log'],
            ['sec', 'admin/security.php', 'shield', 'Security', 'log'],
        ],
        'System' => [
            ['bak', 'admin/backups.php', 'db', 'Backups', 'backup'],
            ['set', 'admin/settings.php', 'set', 'Settings', 'set'],
        ],
    ];
}

/** Menu the current staff member sees: sections with at least one usable item. */
function admin_visible_menu(PDO $conn, string $role): array
{
    $root = dirname(__DIR__, 2) . '/';
    $out = [];
    foreach (admin_menu() as $section => $items) {
        $ok = array_values(array_filter($items, fn($i) => is_file($root . $i[1]) && ($i[4] === null || admin_can($conn, $role, $i[4]))));
        if ($ok) {
            $out[$section] = $ok;
        }
    }
    return $out;
}

function admin_page_start(array $o): void
{
    global $conn, $admin;
    $active = (string) ($o['active'] ?? '');
    $title = (string) ($o['title'] ?? 'Admin');
    $sub = (string) ($o['sub'] ?? '');
    $menu = admin_visible_menu($conn, $admin['role']);
    $flat = array_merge(...array_values($menu ?: [[]]));
    $usable = array_values(array_filter($flat, fn($i) => $i[0] !== 'roles'));
    // Phone bottom bar: the first three pages and "More" (a page that lists every page).
    $bottom = count($usable) > 4 ? array_merge(array_slice($usable, 0, 3), [['more', 'admin/menu.php', 'more', 'More', null]]) : $usable;
    send_security_headers();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · Admin · ExpenseFlow</title>
<meta name="theme-color" content="#0B2545">
<?php require_once __DIR__ . '/../../includes/favicons.php'; ?>
<link rel="stylesheet" href="<?= e(ef_asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(ef_asset('assets/admin.css')) ?>">
<?= $o['head'] ?? '' ?>
</head>
<body>
<div class="fr" data-mode="s">
<div class="ap">
  <aside class="g sb">
    <div class="logo"><img src="<?= e(ef_url('favicon.png')) ?>" alt=""><span class="wm">Admin</span></div>
    <nav class="sbs" aria-label="Admin menu">
    <?php foreach ($menu as $section => $items): ?>
      <div class="sl"><?= e($section) ?></div>
      <?php foreach ($items as $i): ?>
        <a class="nv<?= $i[0] === $active ? ' on' : '' ?>" href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><?= e($i[3]) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>
    <div class="sbf">
    <a class="nv" href="<?= e(ef_url('dashboard.php')) ?>"><?= ef_icon('home') ?>Back to app</a>
    <a class="nv" href="<?= e(ef_url('auth/logout.php')) ?>" data-post data-csrf="<?= e(csrf_token()) ?>"><?= ef_icon('out') ?>Logout</a>
    </div>
  </aside>
  <div class="mn">
    <div class="g tb">
      <div class="tt"><h2><?= e($title) ?></h2><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></div>
      <span class="tag <?= $admin['role'] === 'super' ? 'rs' : 'tb2' ?>"><?= e(admin_role_label($admin['role'])) ?></span>
      <a class="me" href="<?= e(ef_url('profile.php')) ?>"><i class="av"><?= e(ef_initials((string) $admin['name'])) ?></i><span><?= e($admin['name']) ?></span></a>
    </div>
    <div class="view">
<?php
    foreach (['success' => 'ok', 'error' => 'err'] as $key => $cls) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            echo '<div class="msg ' . $cls . '" role="status" data-flash>' . e($_SESSION[$key]) . '</div>';
        }
        unset($_SESSION[$key]);
    }
    $GLOBALS['__admin_bottom'] = [$bottom, $active];
}

function admin_page_end(array $o = []): void
{
    [$bottom, $active] = $GLOBALS['__admin_bottom'] ?? [[], ''];
    ?>
    </div>
  </div>
</div>
<nav class="g bn">
<?php foreach ($bottom as $i): ?>
  <a class="<?= $i[0] === $active ? 'on' : '' ?>" href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><?= e(explode(' ', $i[3])[0]) ?></a>
<?php endforeach; ?>
</nav>
</div>
<script>(function(){var a=document.querySelector('.sbs .nv.on');if(a&&a.scrollIntoView){var n=a.closest('.sbs');if(n&&a.offsetTop+a.offsetHeight>n.clientHeight){n.scrollTop=a.offsetTop-n.clientHeight/2}}})()</script>
<script src="<?= e(ef_asset('assets/client-time.js')) ?>" defer></script>
<script src="<?= e(ef_asset('assets/fx.js')) ?>" defer></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}
