<?php
require_once __DIR__ . '/../includes/public-layout.php';
$url = rtrim($base_url, '/') . '/guides/monthly-budget-kaise-banayein.php';
ef_public_start([
    'title' => 'Monthly budget kaise banayein: tankhwah se bachat tak simple formula' . ' – ExpenseFlow',
    'desc' => 'Tankhwah ke hisab se monthly budget banane ka simple formula: zaroori kharche, shauq aur bachat, ek example ke saath.',
    'path' => 'guides/monthly-budget-kaise-banayein.php', 'h1' => 'Monthly budget kaise banayein', 'article' => true,
    'crumbs' => [['Guides', 'guides/']],
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Monthly budget kaise banayein: tankhwah se bachat tak simple formula', 'description' => 'Tankhwah ke hisab se monthly budget banane ka simple formula: zaroori kharche, shauq aur bachat, ek example ke saath.',
        'datePublished' => '2026-10-23', 'dateModified' => '2026-10-23', 'mainEntityOfPage' => $url,
        'image' => rtrim($base_url, '/') . '/assets/og-image.png',
        'author' => ['@type' => 'Organization', 'name' => 'ExpenseFlow'],
        'publisher' => ['@type' => 'Organization', 'name' => 'ExpenseFlow', 'logo' => ['@type' => 'ImageObject', 'url' => rtrim($base_url, '/') . '/assets/icons/icon-512.png']]]],
]);
?>
<p class="meta">Published 23 October 2026 · <a href="./">All guides</a></p>
<p class="lead">Budget ka matlab paise rokna nahi, har rupay ko pehle se kaam dena hai. Ek simple formula se shuru karein.</p>
<h2>Step 1: apni mahana income likhein</h2>
<p>Tankhwah, side income aur jo bhi paisa pakka aata hai. Jo pakka nahi, usay shamil na karein.</p>
<h2>Step 2: teen hisson mein baantein</h2>
<ul>
<li><b>50% zarooriyat:</b> kiraya, khana, bills, transport, kisht.</li>
<li><b>30% shauq:</b> bahar khana, kapde, tafreeh.</li>
<li><b>20% bachat:</b> emergency fund, committee, goals.</li>
</ul>
<h2>Example</h2>
<p>Agar mahana income 80,000 rupay hai to lagbhag 40,000 zarooriyat, 24,000 shauq aur 16,000 bachat. Agar kiraya zyada hai to zarooriyat 60% bhi ho sakti hai: pehle shauq kam karein, bachat ko na chhedein.</p>
<h2>Step 3: tankhwah milte hi bachat alag karein</h2>
<p>Bachat ko mahine ke aakhir ke liye na chhodein. Pehle alag karein, phir baaqi se kharch karein.</p>
<h2>Step 4: har hafte check karein</h2>
<p>ExpenseFlow mein savings goal ko income ka percentage rakh kar set karein. Dashboard dikhata hai ke aap goal ke kitne qareeb hain.</p>
<p><a class="btn" href="../auth/register.php">Apna goal set karein</a></p>
<?php ef_public_end();
