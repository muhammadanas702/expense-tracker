<?php
require_once __DIR__ . '/includes/public-layout.php';
ef_public_start([
    'title' => 'About ExpenseFlow – a free expense tracker and bill splitter',
    'desc' => 'ExpenseFlow is a free expense tracker, budget planner and bill splitter for personal money and shared groups, available on the web, Android and Windows.',
    'path' => 'about.php', 'h1' => 'About ExpenseFlow',
    'schema' => [['@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About ExpenseFlow', 'url' => rtrim($base_url, '/') . '/about.php']],
]);
?>
<p class="lead">ExpenseFlow helps you see where your money goes and share costs fairly with the people around you.</p>
<h2>What it does</h2>
<ul>
  <li><b>Personal tracking:</b> record income and expenses with categories, notes and dates, repeat monthly bills, and watch your savings goal.</li>
  <li><b>Shared groups:</b> split trips, rent and dinners with friends using equal, exact, percentage or share splits, then see who owes whom and settle up.</li>
  <li><b>Reports:</b> filter by date and export CSV, Excel or PDF. Choose your base currency; exchange rates refresh automatically.</li>
  <li><b>Everywhere:</b> use it in any browser, install it on Android or Windows, or add it to your iPhone home screen. Pages you have opened stay available offline.</li>
</ul>
<h2>Who makes it</h2>
<p>ExpenseFlow is an independent project built by a software engineering student in Lahore, Pakistan. It is free to use and has no advertisements.</p>
<h2>What we will never do</h2>
<p>ExpenseFlow never asks for your bank login, card number or any payment details. It only stores the numbers you type in yourself. Staff will never ask for your password. Read the <a href="privacy.php">Privacy Policy</a> and <a href="terms.php">Terms</a> for details.</p>
<p><a class="btn" href="auth/register.php">Create a free account</a> &nbsp; <a href="guides/">Read the guides</a></p>
<?php ef_public_end();
