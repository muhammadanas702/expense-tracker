<?php
/**
 * Admin page shell: same glass design as the rest of the app, with the staff menu.
 * Menu items only appear when the page file exists AND the role may use it.
 */
require_once __DIR__ . '/../../includes/layout.php';

/**
 * [key, file, icon, label, permission|null, plain-words description] grouped by section.
 * Sections follow the questions a team member asks: who are my users, what is wrong, what can I switch off, what can I change.
 */
function admin_menu(): array
{
    return [
        'Overview' => [
            ['ov', 'admin/index.php', 'grid', 'Dashboard', null, 'The big picture: how many people use the app, new sign-ups and what your team did lately.'],
            ['help', 'admin/help.php', 'lifebuoy', 'Where do I go?', null, 'Tell us what is going wrong in plain words and we show you the exact page to open.'],
        ],
        'People' => [
            ['users', 'admin/users.php', 'users', 'Users', 'view', 'Everyone who has an account. Find a person, block or unblock them, reset a password or delete an account.'],
            ['sup', 'admin/support.php', 'lifebuoy', 'Support', 'support', 'Questions and problems that users sent to you. Read them and write back.'],
            ['ann', 'admin/announcements.php', 'send', 'Announcements', 'bc', 'Write a notice that every user sees at the top of the app (for example "we will be offline tonight").'],
            ['roles', 'admin/roles.php', 'crown', 'Roles & permissions', 'SA', 'Decide which team members can open which admin pages. Owner only.'],
        ],
        'Turn things on / off' => [
            ['feat', 'admin/features.php', 'ck', 'Features on/off', 'set', 'A part of the app is not working? Switch it off here until it is fixed. Nothing is deleted.'],
            ['set', 'admin/settings.php', 'set', 'Settings', 'set', 'Pause the whole website for maintenance, open or close new sign-ups, and other site-wide choices.'],
        ],
        'Look and content' => [
            ['brand', 'admin/branding.php', 'spark', 'Branding', 'set', 'Change the site name, colours, logo, pictures and any text on the website, including the home page.'],
            ['cat', 'admin/categories.php', 'tag', 'Categories', 'cat', 'The expense and income categories people choose from (Food, Travel, Salary...).'],
            ['cur', 'admin/currencies.php', 'globe', 'Currencies', 'cur', 'The money currencies people can use, and their exchange rates.'],
            ['grp', 'admin/groups.php', 'act', 'Groups', 'grp', 'Shared groups people created to split bills. Look inside one or remove a group that is misused.'],
        ],
        'Health and fixing' => [
            ['hlth', 'admin/health.php', 'heart', 'Website health', 'set', 'Is the website healthy right now? Checks the database, e-mail, disk space and recent errors.'],
            ['spd', 'admin/speed.php', 'zap', 'Speed check', 'set', 'Why is the website slow? Measures how fast pages load and what is slowing them down.'],
            ['mail', 'admin/mail.php', 'mail', 'Email log', 'set', 'Every e-mail the website tried to send (login codes, welcome mails) and whether it arrived. Start here when people say "no code came".'],
            ['bak', 'admin/backups.php', 'db', 'Backups', 'backup', 'Safe copies of all the data. Make one before big changes, download it, or restore it if something goes wrong.'],
        ],
        'Safety' => [
            ['sec', 'admin/security.php', 'shield', 'Security', 'log', 'Suspicious sign-in attempts, blocked visitors and the site security checks.'],
            ['log', 'admin/audit.php', 'cal', 'Audit log', 'log', 'A record of what your team members changed in this admin panel, and when. Answers "who did this?".'],
            ['ulog', 'admin/logs.php', 'doc', 'User logs', 'log', 'What users did: logins, sign-ups, password changes. Useful when a user says "I could not sign in".'],
        ],
    ];
}

/** One plain sentence under each menu section, shown on the dashboard and "All pages". */
function admin_section_info(): array
{
    return [
        'Overview' => 'Start here.',
        'People' => 'Your users, their questions, and your team.',
        'Turn things on / off' => 'Pause a broken part or the whole site.',
        'Look and content' => 'How the site looks and what people can pick.',
        'Health and fixing' => 'Something wrong? Check and repair here.',
        'Safety' => 'Who did what, and who is trying to get in.',
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
    $usable = array_values(array_filter($flat, fn($i) => !in_array($i[0], ['roles', 'help'], true)));
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
<?php require_once __DIR__ . '/../../includes/theme.php'; echo ef_theme_head(); ?>
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
        <a class="nv<?= $i[0] === $active ? ' on' : '' ?>" href="<?= e(ef_url($i[1])) ?>" title="<?= e($i[5] ?? '') ?>"><?= ef_icon($i[2]) ?><?= e($i[3]) ?></a>
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
      <div class="tt"><h1><?= e($title) ?></h1><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></div>
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
<script src="<?= e(ef_asset('assets/net-status.js')) ?>" defer></script>
<script src="<?= e(ef_url('assets/pwa-register.js')) ?>" data-app-base="<?= e(rtrim((string) ($GLOBALS['base_url'] ?? ''), '/')) ?>" defer></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}
