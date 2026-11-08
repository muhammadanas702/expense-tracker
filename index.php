<?php
define('EF_LANG_DEFAULT_EN', true); // the public landing page opens in English; Urdu only through its language switch (?lang=ur)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/modules/settings/lib.php';

send_security_headers();

$base = rtrim($base_url, '/');
$canonical = $base . '/';
$title = 'ExpenseFlow – Free Expense Tracker, Budget Planner & Bill Splitter';
$desc = 'Free expense tracker and bill splitter: record income and expenses, plan your budget, split costs with friends, export PDF reports. Works on web, Android and Windows, even offline.';

$features = [
    ['inc', 'Track', 'Income & expenses', 'Record every transaction with categories, notes and dates. Edit or delete anytime.'],
    ['spark', 'Insight', 'Smart insights', 'Financial health, spending trend and top category, explained in plain words.'],
    ['doc', 'Reports', 'Reports & PDF export', 'Filter by date range and download a clean PDF report.'],
    ['globe', 'Currency', 'Multi-currency', 'Choose your base currency; exchange rates update automatically.'],
    ['phone', 'Offline', 'Works offline', 'Install it on your phone like an app and keep going without internet.'],
    ['shield', 'Privacy', 'You stay in control', 'Reset your data or delete your account whenever you want.'],
    ['users', 'Shared', 'Shared groups', 'Split trips, rent and dinners with friends. Equal, exact, percent or share splits.'],
    ['act', 'Balances', 'Clear balances', 'See exactly who owes whom, person by person, and settle up in a tap.'],
    ['rcp', 'Receipts', 'Receipts and comments', 'Attach a photo of the bill and talk about an expense right where it happened.'],
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
$logo = '<span class="logo"><img src="' . e($base) . '/assets/icons/logo-96.webp" alt="" width="38" height="38"><span class="wm">ExpenseFlow</span></span>';
?>
<!DOCTYPE html>
<html<?= ef_html_attrs() ?>>
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
<link rel="stylesheet" href="<?= e($base) ?>/assets/theme-dark.css?v=<?= (int) @filemtime(__DIR__ . '/assets/theme-dark.css') ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/landing3.css?v=<?= (int) @filemtime(__DIR__ . '/assets/landing3.css') ?>">
<?= ef_font_head() . ef_rtl_link() ?>
<script>(function(){try{var m=document.cookie.match(/(?:^|; )ef_theme=(auto|light|dark)/),t=m?m[1]:"auto",d=t==="dark"||(t==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches);if(d){document.documentElement.setAttribute("data-theme","dark");}}catch(e){}})();</script>
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
<body class="lp">
<a class="skip" href="#main">Skip to content</a>
<div class="fr lpf" data-mode="p">
<header class="z-lt g" id="nav">
  <a class="z-lg" href="<?= e($base) ?>/" aria-label="ExpenseFlow"><?= $logo ?></a>
  <nav class="z-ll" aria-label="Sections"><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#faq">FAQ</a></nav>
  <div class="z-lr">
    <?= ef_lang_switch('chip lsw') ?>
    <a class="btn o lhide" href="<?= e($base) ?>/auth/login.php">Log in</a>
    <a class="btn" href="<?= e($base) ?>/auth/register.php">Get started</a>
    <button class="z-bg" id="burger" type="button" aria-expanded="false" aria-controls="menu" aria-label="Menu"><i></i><i></i><i></i></button>
  </div>
  <div class="z-mn g" id="menu" hidden>
    <a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#resources">Resources</a><a href="#faq">FAQ</a>
    <div class="z-mrow"><a class="btn o" href="<?= e($base) ?>/auth/login.php">Log in</a><?= ef_lang_switch('chip lsw') ?></div>
  </div>
</header>

<main id="main" class="z-lm">
<section class="z-hero">
  <div class="z-hx">
    <span class="chip on z-eb">Free · No ads · Works offline</span>
    <h1>Track every rupee.<br><span class="z-grad">Know every month.</span></h1>
    <p class="z-lead">ExpenseFlow keeps your income, expenses and reports organised in one clean place, in any currency.</p>
    <div class="z-cta">
      <a class="btn z-lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
      <a class="btn z-lg o" href="<?= e($base) ?>/auth/login.php">Log in</a>
    </div>
    <ul class="z-tk"><li><?= ef_icon('ck') ?>Works offline</li><li><?= ef_icon('ck') ?>Multi-currency</li><li><?= ef_icon('ck') ?>PDF reports</li></ul>
  </div>
  <div class="z-hm" aria-hidden="true">
    <div class="z-mb"><div class="z-k">Net Balance</div><div class="z-v">Rs 42,500</div><span class="z-bd">▲ 12% vs last month</span>
      <svg viewBox="0 0 300 40" preserveAspectRatio="none"><polyline fill="none" stroke="rgba(255,255,255,.9)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="0,32 30,28 60,31 90,20 120,24 150,14 180,18 210,9 240,13 270,5 300,8"/></svg></div>
    <div class="g z-ml2">
      <div class="z-mr"><span class="z-ci"><?= ef_icon('food') ?></span><span>Food<small>Today</small></span><b class="z-ex">− 850</b></div>
      <div class="z-mr"><span class="z-ci"><?= ef_icon('case') ?></span><span>Salary<small>Oct 1</small></span><b class="z-in">+ 60,000</b></div>
    </div>
  </div>
</section>

<section class="z-sc" id="features">
  <div class="z-sht"><span class="chip">Features</span><h2>Everything you need to stay on top</h2><p>Simple tools that make tracking money a habit instead of a chore.</p></div>
  <div class="z-fg">
<?php foreach ($features as $f): ?>
    <article class="g z-fc"><span class="z-ib"><?= ef_icon($f[0]) ?></span><h3><?= e($f[2]) ?></h3><p><?= e($f[3]) ?></p></article>
<?php endforeach; ?>
  </div>
</section>

<section class="z-sc" id="modes">
  <div class="z-sht"><span class="chip">Personal &amp; Shared</span><h2>Your money, clearly organised</h2><p>Track your own money, or share expenses with friends. Switch between the two in one tap.</p></div>
  <div class="z-pn">
    <article class="g z-fc"><span class="z-ib"><?= ef_icon('wal') ?></span><h3>Personal</h3>
      <?php foreach (['Track income and expenses', 'Category insights', 'Monthly reports with PDF export'] as $t) { echo '<div class="z-ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
    </article>
    <article class="g z-fc"><span class="z-ib"><?= ef_icon('users') ?></span><h3>Shared</h3>
      <?php foreach (['Groups and friends in one place', 'Equal, exact, percent and share splits', 'Person-by-person balances and easy settle up', 'Receipts, comments and an activity feed'] as $t) { echo '<div class="z-ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
    </article>
  </div>
</section>

<section class="z-sc" id="how">
  <div class="z-sht"><span class="chip">How it works</span><h2>Up and running in three steps</h2></div>
  <div class="z-st">
<?php foreach ($steps as $i => $s2): ?>
    <article class="g z-fc"><span class="z-num"><?= $i + 1 ?></span><h3><?= e($s2[0]) ?></h3><p><?= e($s2[1]) ?></p></article>
<?php endforeach; ?>
  </div>
</section>

<section class="z-sc" id="apps">
  <div class="z-sht"><span class="chip">Get the app</span><h2>Use it on your phone or computer</h2><p>Your account works everywhere.</p></div>
  <div class="z-fg z-ap">
    <article class="g z-fc"><span class="z-ib"><?= ef_icon('phone') ?></span><h3>Android</h3><p>Get the Android app from our GitHub releases page. Updates come inside the app.</p>
      <a class="btn" href="<?= e(settings_public('link_android')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download APK</a></article>
    <article class="g z-fc"><span class="z-ib"><?= ef_icon('lap') ?></span><h3>Windows</h3><p>Get the Windows app from our GitHub releases page, or from the Microsoft Store when it is listed.</p>
      <a class="btn" href="<?= e(settings_public('link_windows')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download for Windows</a>
      <?php if (settings_public('link_store') !== ''): ?><a class="btn o" href="<?= e(settings_public('link_store')) ?>" rel="noopener"><?= ef_icon('dl') ?> Get it from Microsoft Store</a><?php endif; ?></article>
    <article class="g z-fc"><span class="z-ib"><?= ef_icon('globe') ?></span><h3>iPhone &amp; any browser</h3><p>On iPhone open this site in Safari, tap Share, then "Add to Home Screen". Chrome and Edge also offer "Install app".</p>
      <a class="btn o" href="<?= e($base) ?>/auth/login.php">Open in browser</a></article>
  </div>
</section>

<section class="z-sc" id="faq">
  <div class="z-sht"><span class="chip">FAQ</span><h2>Questions, answered</h2></div>
  <div class="g z-fq">
<?php foreach ($faq as $i => $f): ?>
    <details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f[0]) ?><?= ef_icon('chev') ?></summary><p><?= e($f[1]) ?></p></details>
<?php endforeach; ?>
  </div>
</section>

<section class="z-sc" id="resources">
  <div class="z-sht"><span class="chip">Resources</span><h2>Guides, privacy &amp; help</h2></div>
  <div class="z-rg">
    <a class="g z-fc" href="<?= e($base) ?>/guides/"><span class="z-ib"><?= ef_icon('globe') ?></span><h3>Money guides</h3><p>Practical articles on budgeting, saving and splitting expenses.</p></a>
    <a class="g z-fc" href="<?= e($base) ?>/privacy.php"><span class="z-ib"><?= ef_icon('lock') ?></span><h3>Privacy Policy</h3><p>What we store, how we protect it and how you can delete it.</p></a>
    <a class="g z-fc" href="<?= e($base) ?>/terms.php"><span class="z-ib"><?= ef_icon('doc') ?></span><h3>Terms of Use</h3><p>The simple rules for using ExpenseFlow fairly and safely.</p></a>
    <a class="g z-fc" href="<?= e($base) ?>/contact.php"><span class="z-ib"><?= ef_icon('phone') ?></span><h3>Contact &amp; support</h3><p>Questions or feedback? Send us a message and we will reply.</p></a>
  </div>
</section>

<section class="z-fin">
  <h2>Ready to take control?</h2>
  <p>Create your account in under a minute.</p>
  <a class="btn z-lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
</section>
</main>

<footer class="z-lf">
  <div class="z-fgd">
    <div><?= $logo ?><p>A simple, private way to track spending, set budgets and share expenses with friends. ExpenseFlow never asks for your bank or card details.</p></div>
    <nav aria-label="Product"><h3>Product</h3><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#faq">FAQ</a></nav>
    <nav aria-label="Learn"><h3>Learn</h3><a href="<?= e($base) ?>/guides/">All guides</a><a href="<?= e($base) ?>/guides/track-monthly-expenses.php">Track monthly expenses</a><a href="<?= e($base) ?>/guides/budget-50-30-20-rule.php">The 50/30/20 budget rule</a></nav>
    <nav aria-label="Company"><h3>Company</h3><a href="<?= e($base) ?>/about.php">About</a><a href="<?= e($base) ?>/contact.php">Contact &amp; support</a><a href="<?= e($base) ?>/privacy.php">Privacy Policy</a><a href="<?= e($base) ?>/terms.php">Terms of Use</a></nav>
  </div>
  <div class="z-fb"><span>© <?= date('Y') ?> ExpenseFlow. All rights reserved.</span><a href="#main">Back to top</a></div>
</footer>
</div>
<script src="<?= e($base) ?>/assets/landing.js?v=<?= (int) @filemtime(__DIR__ . '/assets/landing.js') ?>" defer></script>
</body>
</html>
