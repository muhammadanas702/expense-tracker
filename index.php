<?php
define('EF_LANG_DEFAULT_EN', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/modules/settings/lib.php';
require_once __DIR__ . '/includes/site-chrome.php';

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

?>
<!DOCTYPE html>
<html<?= ef_html_attrs() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f766e">
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
<link rel="stylesheet" href="<?= e($base) ?>/assets/site.css?v=<?= (int) @filemtime(__DIR__ . '/assets/site.css') ?>">
<link rel="stylesheet" href="<?= e($base) ?>/assets/landing.css?v=<?= (int) @filemtime(__DIR__ . '/assets/landing.css') ?>">
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
<body class="s-body" id="top">
<?= ef_site_nav($base, true) ?>
<main id="main">

<section class="l-hero"><div class="s-wrap l-hgrid">
  <div>
    <span class="l-eye"><?= ef_icon('ck') ?>Free · No ads · Works offline</span>
    <h1>Track every rupee.<span class="l-grad">Know every month.</span></h1>
    <p class="l-lead">ExpenseFlow keeps your income, expenses and reports organised in one clean place, in any currency.</p>
    <div class="l-cta">
      <a class="s-btn s-lg" href="<?= e($base) ?>/auth/register.php">Create free account <?= ef_icon('arr') ?></a>
      <a class="s-link" href="#features">See what's inside <?= ef_icon('chev') ?></a>
    </div>
    <p class="l-trust"><?= ef_icon('shield') ?>We never ask for your bank or card details.</p>
  </div>
  <div class="l-stage" aria-hidden="true">
    <div class="l-chip a"><span class="l-ic"><?= ef_icon('inc') ?></span><span>Salary received<small>+ 60,000</small></span></div>
    <div class="l-phone"><div class="l-scr">
      <div class="l-top"><span>Good morning<br><b>Ali</b></span><span class="l-av">A</span></div>
      <div class="l-bal"><small>▲ 12% vs last month</small><strong>Rs 42,500</strong><div class="l-bars"><i></i><i></i><i></i><i></i><i></i><i></i></div></div>
      <div class="l-lh"><span>Food</span><span>Today</span></div>
      <div class="l-tx"><span class="l-ic"><?= ef_icon('food') ?></span><span>Food<small>Today</small></span><b class="l-neg">− 850</b></div>
      <div class="l-tx"><span class="l-ic"><?= ef_icon('case') ?></span><span>Salary<small>Oct 1</small></span><b class="l-pos">+ 60,000</b></div>
      <div class="l-tx"><span class="l-ic"><?= ef_icon('users') ?></span><span>Trip<small>Shared</small></span><b class="l-neg">− 2,400</b></div>
    </div></div>
    <div class="l-chip b"><span class="l-ic"><?= ef_icon('users') ?></span><span>Shared group<small>Ali owes you 1,200</small></span></div>
  </div>
</div></section>

<section class="l-tight l-sec"><div class="s-wrap"><div class="l-stats l-rv">
  <div class="l-stat"><b>100% free</b><span>No ads, ever</span></div>
  <div class="l-stat"><b>Offline</b><span>Works without internet</span></div>
  <div class="l-stat"><b>Any currency</b><span>Rates update automatically</span></div>
  <div class="l-stat"><b>4 ways</b><span>To split a bill</span></div>
</div></div></section>

<section class="l-sec" id="features"><div class="s-wrap">
  <div class="l-head"><span class="l-eye">Features</span><h2>Everything you need to stay on top</h2><p>Simple tools that make tracking money a habit instead of a chore.</p></div>
  <div class="l-grid3">
<?php foreach ($features as $f): ?>
    <article class="l-card l-rv"><span class="l-ic"><?= ef_icon($f[0]) ?></span><h3><?= e($f[1]) ?></h3><p><?= e($f[2]) ?></p></article>
<?php endforeach; ?>
  </div>
</div></section>

<section class="l-sec" id="modes"><div class="s-wrap">
  <div class="l-head"><span class="l-eye">Personal &amp; Shared</span><h2>Your money, clearly organised</h2><p>Track your own money, or share expenses with friends. Switch between the two in one tap.</p></div>
  <div class="l-grid2">
    <article class="l-card l-mode l-rv"><span class="l-ic"><?= ef_icon('wal') ?></span><h3>Personal</h3>
      <div class="l-list"><?php foreach (['Track income and expenses', 'Category insights', 'Monthly reports with PDF export'] as $t) { echo '<div class="l-li">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?></div>
      <div class="l-viz" aria-hidden="true">
        <div class="l-bar"><span>Food</span><i style="width:78%"></i><em>38%</em></div>
        <div class="l-bar"><span>Rent</span><i style="width:55%"></i><em>27%</em></div>
        <div class="l-bar"><span>Travel</span><i style="width:32%"></i><em>16%</em></div>
      </div>
    </article>
    <article class="l-card l-mode l-rv"><span class="l-ic"><?= ef_icon('users') ?></span><h3>Shared</h3>
      <div class="l-list"><?php foreach (['Groups and friends in one place', 'Equal, exact, percent and share splits', 'Person-by-person balances and easy settle up', 'Receipts, comments and an activity feed'] as $t) { echo '<div class="l-li">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?></div>
      <div class="l-viz" aria-hidden="true">
        <div class="l-who"><span class="l-av">A</span>Ali<b class="l-pos">+ 1,200</b></div>
        <div class="l-who"><span class="l-av">S</span>Sara<b class="l-neg">− 400</b></div>
        <div class="l-who"><span class="l-av">Z</span>Zain<b class="l-neg">− 800</b></div>
      </div>
    </article>
  </div>
</div></section>

<section class="l-sec" id="how"><div class="s-wrap">
  <div class="l-head"><span class="l-eye">How it works</span><h2>Up and running in three steps</h2></div>
  <div class="l-grid3">
<?php foreach ($steps as $i => $s2): ?>
    <article class="l-card l-step l-rv"><span class="l-num"><?= $i + 1 ?></span><h3><?= e($s2[0]) ?></h3><p><?= e($s2[1]) ?></p></article>
<?php endforeach; ?>
  </div>
</div></section>

<section class="l-sec" id="apps"><div class="s-wrap">
  <div class="l-head"><span class="l-eye">Get the app</span><h2>Use it on your phone or computer</h2><p>Your account works everywhere.</p></div>
  <div class="l-grid3">
    <article class="l-card l-app l-rv"><span class="l-ic"><?= ef_icon('phone') ?></span><h3>Android</h3><p>Get the Android app from our GitHub releases page. Updates come inside the app.</p>
      <div class="l-b"><a class="s-btn" href="<?= e(settings_public('link_android')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download APK</a></div></article>
    <article class="l-card l-app l-rv"><span class="l-ic"><?= ef_icon('lap') ?></span><h3>Windows</h3><p>Get the Windows app from our GitHub releases page, or from the Microsoft Store when it is listed.</p>
      <div class="l-b"><a class="s-btn" href="<?= e(settings_public('link_windows')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download for Windows</a>
      <?php if (settings_public('link_store') !== ''): ?><a class="s-btn s-ghost" href="<?= e(settings_public('link_store')) ?>" rel="noopener"><?= ef_icon('dl') ?> Get it from Microsoft Store</a><?php endif; ?></div></article>
    <article class="l-card l-app l-rv"><span class="l-ic"><?= ef_icon('globe') ?></span><h3>iPhone &amp; any browser</h3><p>On iPhone open this site in Safari, tap Share, then "Add to Home Screen". Chrome and Edge also offer "Install app".</p>
      <div class="l-b"><a class="s-btn s-ghost" href="<?= e($base) ?>/auth/login.php">Open in browser</a></div></article>
  </div>
</div></section>

<section class="l-sec" id="faq"><div class="s-wrap l-faq">
  <div class="l-head"><span class="l-eye">FAQ</span><h2>Questions, answered</h2><p>Still stuck? <a class="s-link" href="<?= e($base) ?>/contact.php">Contact &amp; support</a></p></div>
  <div class="l-acc">
<?php foreach ($faq as $i => $f): ?>
    <details<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f[0]) ?><?= ef_icon('chev') ?></summary><p><?= e($f[1]) ?></p></details>
<?php endforeach; ?>
  </div>
</div></section>

<section class="l-sec l-tight"><div class="s-wrap"><div class="l-fin l-rv">
  <h2>Ready to take control?</h2>
  <p>Create your account in under a minute.</p>
  <a class="s-btn s-white s-lg" href="<?= e($base) ?>/auth/register.php">Create free account <?= ef_icon('arr') ?></a>
</div></div></section>

</main>
<?= ef_site_footer($base) ?>
<script src="<?= e($base) ?>/assets/site.js?v=<?= (int) @filemtime(__DIR__ . '/assets/site.js') ?>" defer></script>
</body>
</html>
