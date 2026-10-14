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
require_once __DIR__ . '/modules.php';

function ef_url(string $path): string
{
    global $base_url;
    return rtrim((string) ($base_url ?? ''), '/') . '/' . ltrim($path, '/');
}

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
    $mode = ($o['mode'] ?? 'p') === 's' && ef_mode_enabled('s') ? 's' : 'p';
    $active = (string) ($o['active'] ?? '');
    $title = (string) ($o['title'] ?? 'ExpenseFlow');
    $sub = (string) ($o['sub'] ?? '');
    $user = $o['user'] ?? [];
    $name = (string) ($user['name'] ?? '');
    $nav = ef_nav($mode);
    $bottom = array_values(array_filter($nav, fn($n) => $n[3] !== 'sadd'));
    $home = ef_nav('p')[0][0] ?? 'dashboard.php';
    $sharedHome = ef_nav('s')[0][0] ?? null;

    send_security_headers();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · ExpenseFlow</title>
<meta name="theme-color" content="#0B2545">
<link rel="manifest" href="<?= e(ef_url('manifest.json')) ?>">
<link rel="icon" href="<?= e(ef_url('favicon.png')) ?>">
<link rel="stylesheet" href="<?= e(ef_url('assets/app.css')) ?>">
<?= $o['head'] ?? '' ?>
</head>
<body>
<div class="fr" data-mode="<?= e($mode) ?>">
<div class="ap">
  <aside class="g sb">
    <div class="logo"><img src="<?= e(ef_url('favicon.png')) ?>" alt=""><span class="wm">ExpenseFlow</span></div>
    <div class="sl"><?= $mode === 's' ? 'Shared' : 'Personal' ?></div>
    <?php foreach ($nav as $n): ?>
      <a class="nv<?= $n[3] === $active ? ' on' : '' ?>" href="<?= e(ef_url($n[0])) ?>"><?= ef_icon($n[1]) ?><?= e($n[2]) ?></a>
    <?php endforeach; ?>
    <div class="sp"></div>
    <a class="nv" href="<?= e(ef_url('profile.php')) ?>"><?= ef_icon('usr') ?>Profile</a>
    <a class="nv" href="<?= e(ef_url('auth/logout.php')) ?>" data-post data-csrf="<?= e(csrf_token()) ?>"><?= ef_icon('out') ?>Logout</a>
  </aside>
  <div class="mn">
    <div class="g tb">
      <div class="tt"><h2><?= e($title) ?></h2><?php if ($sub !== ''): ?><small><?= e($sub) ?></small><?php endif; ?></div>
      <?php if ($sharedHome): ?>
      <div class="seg">
        <a class="<?= $mode === 'p' ? 'on' : '' ?>" href="<?= e(ef_url($home)) ?>"><?= ef_icon('wal') ?> Personal</a>
        <a class="<?= $mode === 's' ? 'on' : '' ?>" href="<?= e(ef_url($sharedHome)) ?>"><?= ef_icon('users') ?> Shared</a>
      </div>
      <?php endif; ?>
      <a class="me" href="<?= e(ef_url('profile.php')) ?>"><i class="av"><?= e(ef_initials($name)) ?></i><span><?= e($name) ?></span></a>
    </div>
    <div class="view">
<?php
}

function ef_page_end(array $o = []): void
{
    $mode = ($o['mode'] ?? 'p') === 's' && ef_mode_enabled('s') ? 's' : 'p';
    $active = (string) ($o['active'] ?? '');
    $bottom = array_values(array_filter(ef_nav($mode), fn($n) => $n[3] !== 'sadd'));
    ?>
    </div>
  </div>
</div>
<?php if ($mode === 's' && ef_nav('s')): ?>
<a class="fabf" href="<?= e(ef_url('shared/add.php')) ?>" aria-label="Add shared expense"><?= ef_icon('pl') ?></a>
<?php endif; ?>
<nav class="g bn">
<?php foreach ($bottom as $n): ?>
  <a class="<?= $n[3] === $active ? 'on' : '' ?>" href="<?= e(ef_url($n[0])) ?>"><?= ef_icon($n[1]) ?><?= e(str_replace(['Add ', 'Full '], '', $n[2])) ?></a>
<?php endforeach; ?>
</nav>
</div>
<script src="<?= e(ef_url('assets/client-time.js')) ?>" defer></script>
<?= $o['scripts'] ?? '' ?>
</body>
</html>
<?php
}
