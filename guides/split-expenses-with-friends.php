<?php
require_once __DIR__ . '/../includes/public-layout.php';
$url = rtrim($base_url, '/') . '/guides/split-expenses-with-friends.php';
ef_public_start([
    'title' => 'How to split expenses with friends fairly (trips, rent, dinners)' . ' – ExpenseFlow',
    'desc' => 'A simple way to split shared costs with friends: pick the right split type, record every bill, and settle up with the fewest payments.',
    'path' => 'guides/split-expenses-with-friends.php', 'h1' => 'How to split expenses with friends fairly', 'article' => true,
    'crumbs' => [['Guides', 'guides/']],
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'How to split expenses with friends fairly (trips, rent, dinners)', 'description' => 'A simple way to split shared costs with friends: pick the right split type, record every bill, and settle up with the fewest payments.',
        'datePublished' => '2026-10-21', 'dateModified' => '2026-10-21', 'mainEntityOfPage' => $url,
        'image' => rtrim($base_url, '/') . '/assets/og-image.png',
        'author' => ['@type' => 'Organization', 'name' => 'ExpenseFlow'],
        'publisher' => ['@type' => 'Organization', 'name' => 'ExpenseFlow', 'logo' => ['@type' => 'ImageObject', 'url' => rtrim($base_url, '/') . '/assets/icons/icon-512.png']]]],
]);
?>
<p class="meta">Published 21 October 2026 · <a href="./">All guides</a></p>
<p class="lead">Shared costs turn into awkward conversations when nobody remembers who paid for what. A clear routine fixes that.</p>
<h2>1. Record every bill the moment it happens</h2>
<p>Add the expense in the shared group as soon as it is paid: who paid, how much, and what it was for. A quick photo of the receipt settles any later doubt.</p>
<h2>2. Choose the right split type</h2>
<ul>
<li><b>Equal:</b> everyone pays the same, best for a taxi or a shared dinner.</li>
<li><b>Exact amounts:</b> when each person ordered something different.</li>
<li><b>Percentage:</b> when people agree on shares like 60/40, common for rent.</li>
<li><b>Shares:</b> when a person counts as two (a couple) and others as one.</li>
</ul>
<h2>3. Keep a running balance</h2>
<p>Instead of paying each other after every bill, let the balance build up through the trip or month. ExpenseFlow shows exactly who owes whom, person by person.</p>
<h2>4. Settle up once</h2>
<p>When the trip ends, record the payments between members. Each payment is marked in the group so the balance returns to zero and nobody has to remember.</p>
<h2>Common mistakes</h2>
<ul><li>Waiting until the end to remember expenses.</li><li>Splitting everything equally when orders differ widely.</li><li>Not agreeing on one currency for trips abroad.</li></ul>
<p><a class="btn" href="../auth/register.php">Try shared groups free</a></p>
<?php ef_public_end();
