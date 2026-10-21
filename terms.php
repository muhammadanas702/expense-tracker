<?php
require_once __DIR__ . '/includes/public-layout.php';
ef_public_start([
    'title' => 'Terms of Use – ExpenseFlow',
    'desc' => 'The simple rules for using ExpenseFlow, the free expense tracker and bill splitter.',
    'path' => 'terms.php', 'h1' => 'Terms of Use',
]);
?>
<p class="meta">Last updated: 21 October 2026</p>
<p class="lead">By creating an account or using ExpenseFlow you agree to these simple terms.</p>
<h2>Using the service</h2>
<ul>
  <li>You must give a real email address that you own and keep your password private.</li>
  <li>You are responsible for the information you enter and for what happens under your account.</li>
  <li>Do not misuse the service: no attacks, scraping, spam, or attempts to access other people's data.</li>
</ul>
<h2>Your data</h2>
<p>Your records belong to you. We may remove accounts that break these terms or abuse the service. You can delete your account at any time from Profile &amp; Settings.</p>
<h2>No financial advice</h2>
<p>ExpenseFlow is a record-keeping tool. Totals, insights and exchange rates are for information only and are not financial, tax or legal advice. Exchange rates may be delayed or inexact.</p>
<h2>Availability</h2>
<p>The service is provided free and "as is", without guarantees. It may be unavailable at times, and features may change. Keep your own copy of important records by exporting reports.</p>
<h2>Shared groups</h2>
<p>Members of a group can see the expenses, comments and receipts of that group. Only add people you trust and only upload receipts you may share.</p>
<h2>Changes</h2>
<p>We may update these terms; the date above shows the latest version. Continuing to use ExpenseFlow means you accept the update.</p>
<p>See also the <a href="privacy.php">Privacy Policy</a> and the <a href="contact.php">Contact page</a>.</p>
<?php ef_public_end();
