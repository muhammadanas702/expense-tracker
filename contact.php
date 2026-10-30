<?php
require_once __DIR__ . '/includes/public-layout.php';
ef_public_start([
    'title' => 'Contact ExpenseFlow – help and support',
    'desc' => 'How to get help with ExpenseFlow: the in-app Help page, or e-mail the ExpenseFlow team.',
    'path' => 'contact.php', 'h1' => 'Contact & support',
    'schema' => [['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ExpenseFlow', 'url' => rtrim($base_url, '/') . '/contact.php']],
]);
?>
<p class="lead">We read every message.</p>
<h2>If you have an account</h2>
<p>Sign in and open <b>Profile &amp; Settings → Help &amp; support</b>. Write your question; our reply arrives in the app's notification bell and by email.</p>
<h2>If you cannot sign in or have no account</h2>
<p>Write to us at <a href="mailto:expenseflowteam@gmail.com">expenseflowteam@gmail.com</a> and tell us what happened or share your idea. Please do not send passwords, card numbers or other private financial details in the e-mail.</p>
<h2>Staying safe</h2>
<p>ExpenseFlow staff never ask for your password, card number or bank login. If someone does, it is not us.</p>
<p><a class="btn" href="auth/login.php">Log in</a></p>
<?php ef_public_end();
