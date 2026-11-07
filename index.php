<?php
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
$logo = '<span class="logo"><img src="' . e($base) . '/assets/icons/icon-192.png" alt=""><span class="wm">ExpenseFlow</span></span>';
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
<link rel="stylesheet" href="<?= e($base) ?>/assets/landing2.css?v=<?= (int) @filemtime(__DIR__ . '/assets/landing2.css') ?>">
<meta name="theme-color" content="#04070d">
<?= ef_font_head() . ef_rtl_link() ?>
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
<div class="ld" aria-hidden="true"><div class="ld-in"><img src="<?= e($base) ?>/assets/icons/icon-192.png" alt=""><div class="ld-bar"><i></i></div><div class="ld-t">ExpenseFlow</div></div></div>

<header class="nav" id="nav">
  <a class="brand" href="<?= e($base) ?>/"><img src="<?= e($base) ?>/assets/icons/icon-192.png" alt=""><span>ExpenseFlow</span></a>
  <nav class="nl" aria-label="Sections"><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#resources">Resources</a><a href="#faq">FAQ</a></nav>
  <a class="pill" href="<?= e($base) ?>/auth/login.php">Log in</a>
  <a class="pill pr" href="<?= e($base) ?>/auth/register.php">Get started</a>
  <button class="burger" id="burger" type="button" aria-expanded="false" aria-controls="menu" aria-label="Menu"><span></span><span></span><span></span></button>
</header>
<div class="menu" id="menu" aria-label="Menu">
<?php foreach ([['#features', 'Features'], ['#modes', 'Personal & Shared'], ['#how', 'How it works'], ['#apps', 'Get the app'], ['#resources', 'Resources'], ['#faq', 'FAQ']] as $i => $m): ?>
  <a class="mi" href="<?= e($m[0]) ?>"><small>0<?= $i + 1 ?></small><?= e($m[1]) ?></a>
<?php endforeach; ?>
  <div class="mc"><a class="pill" href="<?= e($base) ?>/auth/login.php">Log in</a><a class="pill pr" href="<?= e($base) ?>/auth/register.php">Get started</a></div>
</div>

<main id="main">
<section class="hero">
  <div class="w hg">
    <div>
      <span class="eyebrow"><i></i>Free · No ads · Works offline</span>
      <h1><span class="ch">Track every rupee.</span><span class="gr">Know every month.</span></h1>
      <p class="lead">ExpenseFlow keeps your income, expenses and reports organised in one clean place, in any currency.</p>
      <div class="cta">
        <a class="pill pr lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
        <a class="pill lg" href="<?= e($base) ?>/auth/login.php">Log in</a>
      </div>
      <div class="tr"><span><?= ef_icon('ck') ?>Works offline</span><span><?= ef_icon('ck') ?>Multi-currency</span><span><?= ef_icon('ck') ?>PDF reports</span></div>
    </div>
    <div class="mock" id="mock" aria-hidden="true">
      <div class="fl1"><?= ef_icon('inc') ?>Salary received</div>
      <div class="mk">
        <div class="mc1">
          <div class="k">Net Balance</div><div class="v" data-countup>Rs 42,500</div><span class="bd">▲ 12% vs last month</span>
          <svg class="sp" viewBox="0 0 300 40" preserveAspectRatio="none"><polyline pathLength="1" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="0,32 30,28 60,31 90,20 120,24 150,14 180,18 210,9 240,13 270,5 300,8"/></svg>
        </div>
        <div class="mc2">
          <div class="mr"><span class="ci"><?= ef_icon('food') ?></span><span>Food<small>Today</small></span><b class="ex">− 850</b></div>
          <div class="mr"><span class="ci"><?= ef_icon('case') ?></span><span>Salary<small>Oct 1</small></span><b class="in">+ 60,000</b></div>
        </div>
      </div>
      <div class="fl2"><?= ef_icon('spark') ?>Budget on track</div>
    </div>
  </div>
  <div class="scrollcue" aria-hidden="true"><span>Scroll to explore</span><i></i></div>
</section>

<div class="mq" aria-hidden="true"><div class="mqt">
<?php $tick = ['Track income', 'Split bills', 'Set budgets', 'Export PDF', 'Works offline', 'Any currency'];
for ($k = 0; $k < 2; $k++): ?><span><?= implode('</span><span>', array_map('e', $tick)) ?></span><?php endfor; ?>
</div></div>

<section class="sec" id="features">
  <div class="w">
    <div class="sh rv"><span class="no">01 / What you get</span><h2>Everything you need to stay on top</h2><p>Simple tools that make tracking money a habit instead of a chore.</p></div>
    <div class="fg">
<?php foreach ($features as $i => $f): ?>
      <article class="cd rv" style="--d:<?= ($i % 3) * .08 ?>s"><div class="tp"><span class="n">0<?= $i + 1 ?></span><span class="ib"><?= ef_icon($f[0]) ?></span></div><div class="cat"><?= e($f[1]) ?></div><h3><?= e($f[2]) ?></h3><p><?= e($f[3]) ?></p></article>
<?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec" id="modes">
  <div class="w">
    <div class="sh rv"><span class="no">02 / Two ways</span><h2>Your money, clearly organised</h2><p>Track your own money, or share expenses with friends. Switch between the two in one tap.</p></div>
    <div class="pn">
      <article class="cd rv"><span class="ib"><?= ef_icon('wal') ?></span><h3>Personal</h3>
        <?php foreach (['Track income and expenses', 'Category insights', 'Monthly reports with PDF export'] as $t) { echo '<div class="ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
      </article>
      <article class="cd rv" style="--d:.1s"><span class="ib"><?= ef_icon('users') ?></span><h3>Shared</h3>
        <?php foreach (['Groups and friends in one place', 'Equal, exact, percent and share splits', 'Person-by-person balances and easy settle up', 'Receipts, comments and an activity feed'] as $t) { echo '<div class="ck">' . ef_icon('ck') . '<span>' . e($t) . '</span></div>'; } ?>
      </article>
    </div>
  </div>
</section>

<section class="sec" id="how">
  <div class="w">
    <div class="sh rv"><span class="no">03 / Three steps</span><h2>How it works</h2><p>Up and running in three steps.</p></div>
    <div class="st">
<?php foreach ($steps as $i => $s2): ?>
      <article class="cd rv" style="--d:<?= $i * .1 ?>s"><div class="big">0<?= $i + 1 ?></div><h3><?= e($s2[0]) ?></h3><p><?= e($s2[1]) ?></p></article>
<?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec ap" id="apps">
  <div class="w">
    <div class="sh rv"><span class="no">04 / Install it</span><h2>Get the app</h2><p>Use ExpenseFlow on your phone or computer. Your account works everywhere.</p></div>
    <div class="fg">
      <article class="cd rv"><div class="tp"><span class="n">Android</span><span class="ib"><?= ef_icon('phone') ?></span></div><h3>Android</h3><p>Get the Android app from our GitHub releases page. Updates come inside the app.</p>
        <a class="pill pr" href="<?= e(settings_public('link_android')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download APK</a></article>
      <article class="cd rv" style="--d:.1s"><div class="tp"><span class="n">Windows</span><span class="ib"><?= ef_icon('lap') ?></span></div><h3>Windows</h3><p>Get the Windows app from our GitHub releases page, or from the Microsoft Store when it is listed.</p>
        <a class="pill pr" href="<?= e(settings_public('link_windows')) ?>" rel="noopener"><?= ef_icon('dl') ?> Download for Windows</a>
        <?php if (settings_public('link_store') !== ''): ?><a class="pill" href="<?= e(settings_public('link_store')) ?>" rel="noopener"><?= ef_icon('dl') ?> Get it from Microsoft Store</a><?php endif; ?></article>
      <article class="cd rv" style="--d:.2s"><div class="tp"><span class="n">Web</span><span class="ib"><?= ef_icon('globe') ?></span></div><h3>iPhone &amp; any browser</h3><p>On iPhone open this site in Safari, tap Share, then "Add to Home Screen". Chrome and Edge also offer "Install app".</p>
        <a class="pill" href="<?= e($base) ?>/auth/login.php">Open in browser</a></article>
    </div>
  </div>
</section>

<section class="sec" id="faq">
  <div class="w">
    <div class="sh rv"><span class="no">05 / FAQ</span><h2>Questions, answered</h2></div>
    <div class="fq">
<?php foreach ($faq as $i => $f): ?>
      <details class="rv" style="--d:<?= $i * .05 ?>s"<?= $i === 0 ? ' open' : '' ?>><summary><?= e($f[0]) ?><?= ef_icon('chev') ?></summary><p><?= e($f[1]) ?></p></details>
<?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sec" id="resources">
  <div class="w">
    <div class="sh rv"><span class="no">06 / Learn more</span><h2>Guides, privacy &amp; help</h2><p>Everything you need to use ExpenseFlow with confidence.</p></div>
    <div class="rg">
      <a class="cd rv" href="<?= e($base) ?>/guides/"><span class="ib"><?= ef_icon('globe') ?></span><h3>Money guides</h3><p>Practical articles on budgeting, saving and splitting expenses.</p><span class="go">Read guides <?= ef_icon('arr') ?></span></a>
      <a class="cd rv" style="--d:.06s" href="<?= e($base) ?>/privacy.php"><span class="ib"><?= ef_icon('lock') ?></span><h3>Privacy Policy</h3><p>What we store, how we protect it and how you can delete it.</p><span class="go">Read policy <?= ef_icon('arr') ?></span></a>
      <a class="cd rv" style="--d:.12s" href="<?= e($base) ?>/terms.php"><span class="ib"><?= ef_icon('doc') ?></span><h3>Terms of Use</h3><p>The simple rules for using ExpenseFlow fairly and safely.</p><span class="go">Read terms <?= ef_icon('arr') ?></span></a>
      <a class="cd rv" style="--d:.18s" href="<?= e($base) ?>/contact.php"><span class="ib"><?= ef_icon('phone') ?></span><h3>Contact &amp; support</h3><p>Questions or feedback? Send us a message and we will reply.</p><span class="go">Get in touch <?= ef_icon('arr') ?></span></a>
    </div>
  </div>
</section>

<section class="fin">
  <div class="w rv">
    <span class="no">Your next move</span>
    <h2>Ready to take control?</h2>
    <p>Create your account in under a minute.</p>
    <a class="pill pr lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
  </div>
</section>
</main>

<footer class="ftr">
  <div class="w">
    <div class="fgd">
      <div><a class="brand" href="<?= e($base) ?>/"><img src="<?= e($base) ?>/assets/icons/icon-192.png" alt=""><span>ExpenseFlow</span></a><p>A simple, private way to track spending, set budgets and share expenses with friends. ExpenseFlow never asks for your bank or card details.</p></div>
      <nav aria-label="Product"><h3>Product</h3><a href="#features">Features</a><a href="#modes">Personal &amp; Shared</a><a href="#how">How it works</a><a href="#apps">Get the app</a><a href="#faq">FAQ</a></nav>
      <nav aria-label="Learn"><h3>Learn</h3><a href="<?= e($base) ?>/guides/">All guides</a><a href="<?= e($base) ?>/guides/track-monthly-expenses.php">Track monthly expenses</a><a href="<?= e($base) ?>/guides/budget-50-30-20-rule.php">The 50/30/20 budget rule</a></nav>
      <nav aria-label="Company"><h3>Company</h3><a href="<?= e($base) ?>/about.php">About</a><a href="<?= e($base) ?>/contact.php">Contact &amp; support</a><a href="<?= e($base) ?>/privacy.php">Privacy Policy</a><a href="<?= e($base) ?>/terms.php">Terms of Use</a></nav>
      <nav aria-label="Account"><h3>Account</h3><a href="<?= e($base) ?>/auth/login.php">Log in</a><a href="<?= e($base) ?>/auth/register.php">Create account</a></nav>
    </div>
    <div class="wmk" aria-hidden="true">ExpenseFlow</div>
    <div class="fb"><span>© <?= date('Y') ?> ExpenseFlow. All rights reserved.</span><span><a href="<?= e($base) ?>/privacy.php">Privacy</a> · <a href="<?= e($base) ?>/terms.php">Terms</a> · <a href="<?= e($base) ?>/contact.php">Contact</a> · <a href="#main">Back to top</a></span></div>
  </div>
</footer>
<button class="mo" id="mo" type="button" aria-pressed="false" data-pause="Pause motion" data-play="Play motion"><?= ef_icon('spark') ?><span>Pause motion</span></button>
<script src="<?= e($base) ?>/assets/landing.js?v=<?= (int) @filemtime(__DIR__ . '/assets/landing.js') ?>" defer></script>
</body>
</html>
