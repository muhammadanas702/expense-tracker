<?php
require_once __DIR__ . '/../includes/public-layout.php';
$url = rtrim($base_url, '/') . '/guides/budget-50-30-20-rule.php';
ef_public_start([
    'title' => 'The 50/30/20 budget rule explained with a simple example' . ' – ExpenseFlow',
    'desc' => 'Learn the 50/30/20 budgeting rule: needs, wants and savings, with a worked example and tips to adapt it to your income.',
    'path' => 'guides/budget-50-30-20-rule.php', 'h1' => 'The 50/30/20 budget rule explained', 'article' => true,
    'crumbs' => [['Guides', 'guides/']],
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'The 50/30/20 budget rule explained with a simple example', 'description' => 'Learn the 50/30/20 budgeting rule: needs, wants and savings, with a worked example and tips to adapt it to your income.',
        'datePublished' => '2026-10-21', 'dateModified' => '2026-10-21', 'mainEntityOfPage' => $url,
        'image' => rtrim($base_url, '/') . '/assets/og-image.png',
        'author' => ['@type' => 'Organization', 'name' => 'ExpenseFlow'],
        'publisher' => ['@type' => 'Organization', 'name' => 'ExpenseFlow', 'logo' => ['@type' => 'ImageObject', 'url' => rtrim($base_url, '/') . '/assets/icons/icon-512.png']]]],
]);
?>
<p class="meta">Published 21 October 2026 · <a href="./">All guides</a></p>
<p class="lead">The 50/30/20 rule is a simple starting point for a budget: split your income into needs, wants and savings.</p>
<h2>The three parts</h2>
<ul>
<li><b>50% needs:</b> rent, food, bills, transport, minimum debt payments.</li>
<li><b>30% wants:</b> eating out, shopping, entertainment, hobbies.</li>
<li><b>20% savings:</b> emergency fund, goals, investments.</li>
</ul>
<h2>Example</h2>
<p>With a monthly income of 100,000: about 50,000 for needs, 30,000 for wants and 20,000 for savings.</p>
<h2>Adapting it</h2>
<p>If rent is high, your needs may be 60%. That is fine: reduce wants first and protect savings, even if the savings share is only 10% at the start.</p>
<h2>How ExpenseFlow helps</h2>
<p>Set your savings goal as a percentage of income in Profile &amp; Settings. The dashboard then shows how close you are, and your categories show whether needs or wants are taking too much.</p>
<p><a class="btn" href="../auth/register.php">Set your savings goal</a></p>
<?php ef_public_end();
