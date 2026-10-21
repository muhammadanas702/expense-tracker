<?php
require_once __DIR__ . '/includes/public-layout.php';
ef_public_start([
    'title' => 'Privacy Policy – ExpenseFlow',
    'desc' => 'What ExpenseFlow stores, why, who can see it, and how you can export or delete your data.',
    'path' => 'privacy.php', 'h1' => 'Privacy Policy',
]);
?>
<p class="meta">Last updated: 21 October 2026</p>
<p class="lead">Short version: we store only what you type in, we do not sell it, and you can delete everything yourself.</p>
<h2>What we store</h2>
<ul>
  <li><b>Account:</b> your name, email address and a one-way hash of your password (we cannot read your password).</li>
  <li><b>Your records:</b> the income, expenses, categories, notes, receipts, groups and comments you create.</li>
  <li><b>Security logs:</b> sign-in and account events with the time and IP address, kept to detect abuse and help with support.</li>
  <li><b>Support messages</b> you send us through the Help page.</li>
</ul>
<h2>Cookies</h2>
<p>We use one essential cookie to keep you signed in (up to 30 days, and you are signed out after 3 days without use). We do not use advertising or tracking cookies.</p>
<h2>Who can see your data</h2>
<p>Only you, plus the members of a shared group for the expenses inside that group. A small number of administrators can view account details to provide support and keep the service safe; their actions are recorded in an audit log. We do not sell or rent your data.</p>
<h2>Services we rely on</h2>
<p>Email (sign-up codes and support replies) is sent through an SMTP provider. Exchange rates come from a public rates service, and charts are drawn with an open-source library loaded from a public CDN. The site is hosted on InfinityFree.</p>
<h2>Your choices</h2>
<ul>
  <li>Export your data from the Full Report page (CSV, Excel, PDF).</li>
  <li>Reset your data or permanently delete your account in Profile &amp; Settings. Deleting removes your records.</li>
  <li>Turn notifications off in Profile &amp; Settings.</li>
</ul>
<h2>Children</h2>
<p>ExpenseFlow is not intended for children under 13.</p>
<h2>Contact</h2>
<p>Questions about privacy: see the <a href="contact.php">Contact page</a>.</p>
<?php ef_public_end();
