<?php
require_once __DIR__ . '/../includes/public-layout.php';
$url = rtrim($base_url, '/') . '/guides/track-monthly-expenses.php';
ef_public_start([
    'title' => 'How to track your monthly expenses in 10 minutes a week' . ' – ExpenseFlow',
    'desc' => 'A practical routine for tracking monthly expenses: categories that work, a weekly review, and how to spot where your money leaks.',
    'path' => 'guides/track-monthly-expenses.php', 'h1' => 'How to track your monthly expenses', 'article' => true,
    'crumbs' => [['Guides', 'guides/']],
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'How to track your monthly expenses in 10 minutes a week', 'description' => 'A practical routine for tracking monthly expenses: categories that work, a weekly review, and how to spot where your money leaks.',
        'datePublished' => '2026-10-21', 'dateModified' => '2026-10-21', 'mainEntityOfPage' => $url,
        'image' => rtrim($base_url, '/') . '/assets/og-image.png',
        'author' => ['@type' => 'Organization', 'name' => 'ExpenseFlow'],
        'publisher' => ['@type' => 'Organization', 'name' => 'ExpenseFlow', 'logo' => ['@type' => 'ImageObject', 'url' => rtrim($base_url, '/') . '/assets/icons/icon-512.png']]]],
]);
?>
<p class="meta">Published 21 October 2026 · <a href="./">All guides</a></p>
<p class="lead">You do not need a complicated spreadsheet. You need one place, a few categories and a short weekly habit.</p>
<h2>1. Use a small set of categories</h2>
<p>Start with Food, Transport, Bills, Shopping, Health, Education and Other. Too many categories make logging slow, so you stop doing it.</p>
<h2>2. Log expenses the same day</h2>
<p>Add each expense as it happens, even a small one. Small daily purchases usually add up to the biggest surprise at the end of the month.</p>
<h2>3. Set up repeating items</h2>
<p>Rent, subscriptions and installments repeat every month. Mark them as monthly repeats so they are added for you.</p>
<h2>4. Review once a week</h2>
<p>Ten minutes on the weekend: open the dashboard, check the top category and the spending trend, and ask which expense you would skip next time.</p>
<h2>5. Compare month to month</h2>
<p>Use the date filters to compare this month with the last one. A rise in one category is the easiest place to save.</p>
<p><a class="btn" href="../auth/register.php">Start tracking free</a></p>
<?php ef_public_end();
