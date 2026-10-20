<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/icons.php';

send_security_headers();

$base = rtrim($base_url, '/');
$canonical = $base . '/';
$title = 'ExpenseFlow – Expense Tracker & Budget Planner';
$desc = 'Track expenses and income, manage your budget, and view financial reports with ExpenseFlow, an online expense tracker and budget planner.';

$features = [
    ['inc', 'Income & expenses', 'Record every transaction with categories, notes and dates. Edit or delete anytime.'],
    ['spark', 'Smart insights', 'Financial health, spending trend and top category, explained in plain words.'],
    ['doc', 'Reports & PDF export', 'Filter by date range and download a clean PDF report.'],
    ['globe', 'Multi-currency', 'Choose your base currency; exchange rates update automatically.'],
    ['phone', 'Works offline', 'Install it on your phone like an app and keep going without internet.'],
    ['shield', 'You stay in control', 'Reset your data or delete your account whenever you want.'],
    ['users', 'Shared groups', 'Split trips, rent and dinners with friends. Equal, exact, percent or share splits.'],
    ['act', 'Clear balances', 'See exactly who owes whom, person by person, and settle up in a tap.'],
    ['rcp', 'Receipts and comments', 'Attach a photo of the bill and talk about an expense right where it happened.'],
];
$steps = [
    ['Create your account', 'Sign up and choose your base currency.'],
    ['Add your money', 'Record income and expenses.'],
    ['See the full picture', 'Get insights, reports and clear totals.'],
];
$faq = [
    ['Does it work without internet?', 'Yes. Install ExpenseFlow on your phone and the app shell stays available offline.'],
    ['Which currencies are supported?', 'Pick any supported base currency; exchange rates are refreshed automatically.'],
    ['Can I download my reports?', 'Yes, filter by date and export a PDF report from the Full Report page.'],
    ['Can I delete my account?', 'Yes. You can reset your data or permanently delete your account from Profile.'],
];
$logo = '<span class="logo"><img src="' . e($base) . '/assets/icons/icon-192.png" alt=""><span class="wm">ExpenseFlow</span></span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php require __DIR__ . '/includes/pwa-head.php'; ?>
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ExpenseFlow">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
<script>document.documentElement.classList.add('js')</script>
</head>
<body>
<div class="fr" data-mode="p">
<header class="g nb">
  <a class="logo" href="<?= e($base) ?>/" style="padding:0"><?= $logo ?></a>
  <nav class="lk" aria-label="Sections"><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#faq">FAQ</a></nav>
  <a class="lnk" href="<?= e($base) ?>/auth/login.php">Log in</a>
  <a class="btn" href="<?= e($base) ?>/auth/register.php">Get started</a>
</header>

<section class="h2s">
  <div>
    <h1>Track every rupee.<br><span class="grad">Know every month.</span></h1>
    <p class="lead">ExpenseFlow keeps your income, expenses and reports organised in one clean place, in any currency.</p>
    <div class="cta">
      <a class="btn lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
      <a class="btn lg o" href="<?= e($base) ?>/auth/login.php">Log in</a>
    </div>
    <div class="trust"><span><?= ef_icon('ck') ?>Works offline</span><span><?= ef_icon('ck') ?>Multi-currency</span><span><?= ef_icon('ck') ?>PDF reports</span></div>
  </div>
  <div class="hv" aria-hidden="true">
    <div class="g chp"><?= ef_icon('inc') ?>Salary received</div>
    <div class="g card hero" style="padding:22px 22px 44px">
      <div class="k">Net Balance</div><div class="v" data-countup>Rs 42,500</div><span class="badge">▲ 12% vs last month</span>
      <svg viewBox="0 0 300 40" width="100%" height="40" preserveAspectRatio="none" style="margin-top:12px"><polyline pathLength="1" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2.5" stroke-linecap="round" points="0,30 40,26 80,28 120,16 170,20 220,8 300,4"/></svg>
    </div>
    <div class="g card m2">
      <div class="row"><span class="cat"><?= ef_icon('food', '#0f766e') ?></span><span>Food<small>Today</small></span><b class="ex">− 850</b></div>
      <div class="row"><span class="cat"><?= ef_icon('case', '#0f766e') ?></span><span>Salary<small>Oct 1</small></span><b class="in">+ 60,000</b></div>
    </div>
  </div>
</section>

<section class="sec" id="features">
  <h2 class="rv">Everything you need to stay on top</h2>
  <p class="sub2">Simple tools that make tracking money a habit instead of a chore.</p>
  <div class="F3">
<?php foreach ($features as $i => $f): ?>
    <div class="g ft rv" style="--d:<?= ($i % 3) * .08 ?>s"><div class="ib"><?= ef_icon($f[0]) ?></div><div><b><?= e($f[1]) ?></b><p><?= e($f[2]) ?></p></div></div>
<?php endforeach; ?>
  </div>
</section>

<section class="sec" id="modes">
  <h2 class="rv">Your money, clearly organised</h2>
  <p class="sub2">Track your own money, or share expenses with friends. Switch between the two in one tap.</p>
  <div class="GE">
    <div class="g md rv"><div class="ib"><?= ef_icon('wal') ?></div><h3>Personal</h3>
      <?php foreach (['Track income and expenses', 'Category insights', 'Monthly reports with PDF export'] as $t) { echo '<div class="ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
    </div>
    <div class="g md rv" style="--d:.1s"><div class="ib"><?= ef_icon('users') ?></div><h3>Shared</h3>
      <?php foreach (['Groups and friends in one place', 'Equal, exact, percent and share splits', 'Person-by-person balances and easy settle up', 'Receipts, comments and an activity feed'] as $t) { echo '<div class="ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
    </div>
  </div>
</section>

<section class="sec" id="how">
  <h2 class="rv">How it works</h2>
  <p class="sub2">Up and running in three steps.</p>
  <div class="F3">
<?php foreach ($steps as $i => $s): ?>
    <div class="g ft rv" style="--d:<?= $i * .1 ?>s"><div class="sn"><?= $i + 1 ?></div><b><?= e($s[0]) ?></b><p><?= e($s[1]) ?></p></div>
<?php endforeach; ?>
  </div>
</section>

<section class="sec" id="faq">
  <h2 class="rv">Questions, answered</h2>
  <p class="sub2"></p>
  <div style="max-width:680px;margin:0 auto">
<?php foreach ($faq as $i => $f): ?>
    <details class="g rv" style="--d:<?= $i * .06 ?>s"<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f[0]) ?><?= ef_icon('chev') ?></summary><p><?= e($f[1]) ?></p></details>
<?php endforeach; ?>
  </div>
</section>

<section class="band rv">
  <h2>Ready to take control?</h2>
  <p>Create your account in under a minute.</p>
  <a class="btn lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
</section>

<footer class="fo"><?= $logo ?><span>© <?= date('Y') ?> ExpenseFlow</span>
  <span class="lk2"><a href="<?= e($base) ?>/auth/login.php">Log in</a><a href="<?= e($base) ?>/auth/register.php">Register</a></span></footer>
</div>
<script src="<?= e($base) ?>/assets/fx.js?v=<?= (int) @filemtime(__DIR__ . '/assets/fx.js') ?>" defer></script>
</body>
</html>
