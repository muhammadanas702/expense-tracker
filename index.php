<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/icons.php';

send_security_headers();

$base = rtrim($base_url, '/');
$canonical = $base . '/';
$title = 'ExpenseFlow – Free Expense Tracker, Budget Planner & Bill Splitter';
$desc = 'Free expense tracker and bill splitter: record income and expenses, plan your budget, split costs with friends, export PDF reports. Works on web, Android and Windows, even offline.';

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
    ['Is ExpenseFlow free?', 'Yes. ExpenseFlow is free to use and has no advertisements.'],
    ['How do I split a bill with friends?', 'Create a shared group, invite friends with a link, add an expense and choose equal, exact, percentage or share split. ExpenseFlow then shows who owes whom so you can settle up.'],
    ['Is my financial data safe?', 'ExpenseFlow never asks for bank or card details. You only type in your own records, passwords are stored as one-way hashes, and you can export or delete your data at any time.'],
    ['Is there an Android or Windows app?', 'Yes. Download the Android APK or the Windows installer from the Get the app section. On iPhone, add the site to your home screen from Safari.'],
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
<meta property="og:image" content="<?= e($base) ?>/assets/og-image.png">
<meta property="og:locale" content="en_US">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= e($base) ?>/assets/og-image.png">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/app.css') ?>">
<?php
$ld = [
  ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'ExpenseFlow', 'url' => $canonical, 'description' => $desc, 'inLanguage' => 'en'],
  ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'ExpenseFlow', 'url' => $canonical, 'logo' => $base . '/assets/icons/icon-512.png'],
  ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'ExpenseFlow', 'url' => $canonical,
   'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web, Android, Windows', 'description' => $desc,
   'image' => $base . '/assets/og-image.png', 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD']],
  ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)],
];
foreach ($ld as $block): ?>
<script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<script>document.documentElement.classList.add('js')</script>
</head>
<body>
<div class="fr" data-mode="p">
<header class="g nb">
  <a class="logo" href="<?= e($base) ?>/" style="padding:0"><?= $logo ?></a>
  <nav class="lk" aria-label="Sections"><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#resources">Resources</a><a href="#faq">FAQ</a></nav>
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

<section class="sec" id="apps">
  <h2 class="rv">Get the app</h2>
  <p class="sub2">Use ExpenseFlow on your phone or computer. Your account works everywhere.</p>
  <div class="F3">
    <div class="g ft rv"><div class="sn"><?= ef_icon('phone') ?></div><b>Android</b><p>Download the APK, open it and allow installing from this source when Android asks. Updates come inside the app.</p>
      <a class="btn" style="margin-top:12px" href="https://github.com/muhammadanas702/expense-tracker/releases/latest/download/ExpenseFlow.apk" rel="noopener"><?= ef_icon('dl') ?> Download APK</a></div>
    <div class="g ft rv" style="--d:.1s"><div class="sn"><?= ef_icon('lap') ?></div><b>Windows</b><p>Download the installer and run it. If Windows shows "protected your PC", choose More info, then Run anyway.</p>
      <a class="btn" style="margin-top:12px" href="https://github.com/muhammadanas702/expense-tracker/releases/latest/download/ExpenseFlow-Setup.exe" rel="noopener"><?= ef_icon('dl') ?> Download for Windows</a></div>
    <div class="g ft rv" style="--d:.2s"><div class="sn"><?= ef_icon('globe') ?></div><b>iPhone &amp; any browser</b><p>On iPhone open this site in Safari, tap Share, then "Add to Home Screen". Chrome and Edge also offer "Install app".</p>
      <a class="btn o" style="margin-top:12px" href="<?= e($base) ?>/auth/login.php">Open in browser</a></div>
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

<section class="sec" id="resources">
  <h2 class="rv">Guides, privacy &amp; help</h2>
  <p class="sub2">Everything you need to use ExpenseFlow with confidence.</p>
  <div class="rsg">
    <a class="g rsc rv" href="<?= e($base) ?>/guides/"><span class="rsi"><?= ef_icon('globe') ?></span><b>Money guides</b><p>Practical articles on budgeting, saving and splitting expenses.</p><span class="rsm">Read guides <?= ef_icon('arr') ?></span></a>
    <a class="g rsc rv" style="--d:.06s" href="<?= e($base) ?>/privacy.php"><span class="rsi"><?= ef_icon('lock') ?></span><b>Privacy Policy</b><p>What we store, how we protect it and how you can delete it.</p><span class="rsm">Read policy <?= ef_icon('arr') ?></span></a>
    <a class="g rsc rv" style="--d:.12s" href="<?= e($base) ?>/terms.php"><span class="rsi"><?= ef_icon('doc') ?></span><b>Terms of Use</b><p>The simple rules for using ExpenseFlow fairly and safely.</p><span class="rsm">Read terms <?= ef_icon('arr') ?></span></a>
    <a class="g rsc rv" style="--d:.18s" href="<?= e($base) ?>/contact.php"><span class="rsi"><?= ef_icon('phone') ?></span><b>Contact &amp; support</b><p>Questions or feedback? Send us a message and we will reply.</p><span class="rsm">Get in touch <?= ef_icon('arr') ?></span></a>
  </div>
</section>

<section class="band rv">
  <h2>Ready to take control?</h2>
  <p>Create your account in under a minute.</p>
  <a class="btn lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
</section>

<footer class="ft2">
  <div class="ft2g">
    <div class="ft2b"><?= $logo ?><p>A simple, private way to track spending, set budgets and share expenses with friends. ExpenseFlow never asks for your bank or card details.</p></div>
    <nav aria-label="Product"><h3>Product</h3><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#faq">FAQ</a></nav>
    <nav aria-label="Learn"><h3>Learn</h3><a href="<?= e($base) ?>/guides/">All guides</a><a href="<?= e($base) ?>/guides/track-monthly-expenses.php">Track monthly expenses</a><a href="<?= e($base) ?>/guides/budget-50-30-20-rule.php">50/30/20 budget rule</a><a href="<?= e($base) ?>/guides/split-expenses-with-friends.php">Split expenses with friends</a></nav>
    <nav aria-label="Company"><h3>Company</h3><a href="<?= e($base) ?>/about.php">About</a><a href="<?= e($base) ?>/contact.php">Contact &amp; support</a><a href="<?= e($base) ?>/privacy.php">Privacy Policy</a><a href="<?= e($base) ?>/terms.php">Terms of Use</a></nav>
    <nav aria-label="Account"><h3>Account</h3><a href="<?= e($base) ?>/auth/login.php">Log in</a><a href="<?= e($base) ?>/auth/register.php">Create account</a></nav>
  </div>
  <div class="ft2s"><span>© <?= date('Y') ?> ExpenseFlow. All rights reserved.</span><span><a href="<?= e($base) ?>/privacy.php">Privacy</a> · <a href="<?= e($base) ?>/terms.php">Terms</a> · <a href="<?= e($base) ?>/contact.php">Contact</a></span></div>
</footer>
</div>
<script src="<?= e($base) ?>/assets/fx.js?v=<?= (int) @filemtime(__DIR__ . '/assets/fx.js') ?>" defer></script>
</body>
</html>
