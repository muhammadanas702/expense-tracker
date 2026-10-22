<?php
require_once __DIR__ . '/../includes/public-layout.php';
$url = rtrim($base_url, '/') . '/guides/trip-expense-sharing-app.php';
ef_public_start([
    'title' => 'Trip ke kharche dosto mein kaise baantein: group expense sharing app' . ' – ExpenseFlow',
    'desc' => 'Trip, hostel ya flat ke kharche dosto mein baantne ka tareeqa: expense group, split types aur settle up. Free app ke saath.',
    'path' => 'guides/trip-expense-sharing-app.php', 'h1' => 'Trip ke kharche dosto mein kaise baantein', 'article' => true,
    'crumbs' => [['Guides', 'guides/']],
    'schema' => [['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'Trip ke kharche dosto mein kaise baantein: group expense sharing app', 'description' => 'Trip, hostel ya flat ke kharche dosto mein baantne ka tareeqa: expense group, split types aur settle up. Free app ke saath.',
        'datePublished' => '2026-10-23', 'dateModified' => '2026-10-23', 'mainEntityOfPage' => $url,
        'image' => rtrim($base_url, '/') . '/assets/og-image.png',
        'author' => ['@type' => 'Organization', 'name' => 'ExpenseFlow'],
        'publisher' => ['@type' => 'Organization', 'name' => 'ExpenseFlow', 'logo' => ['@type' => 'ImageObject', 'url' => rtrim($base_url, '/') . '/assets/icons/icon-512.png']]]],
]);
?>
<p class="meta">Published 23 October 2026 · <a href="./">All guides</a></p>
<p class="lead">Trip ke baad sab se mushkil kaam hisab hota hai: kis ne kya diya aur kis ne kitna dena hai. Ek shared group is ko khud sambhal leta hai.</p>
<h2>Kaise kaam karta hai</h2>
<ol>
<li>Group banayein (jaise "Hunza trip") aur link se dosto ko bulayein.</li>
<li>Jis ne bhi payment ki, wo usi waqt expense add kare aur batae ke kis kis ke liye thi.</li>
<li>Split ka tareeqa chunein: barabar, exact amount, percentage ya shares.</li>
<li>Aakhir mein app khud dikhata hai ke kis ne kis ko kitna dena hai.</li>
<li>Payment karke "settle up" mark kar dein.</li>
</ol>
<h2>Kaun sa split kab</h2>
<ul>
<li><b>Barabar:</b> taxi, petrol, hotel ka kamra.</li>
<li><b>Exact:</b> jab sab ne alag alag order kiya.</li>
<li><b>Percentage ya shares:</b> jab ek bande ke saath family ho.</li>
</ul>
<h2>Tips</h2>
<ul><li>Receipt ki photo laga dein, baad mein bahes nahi hoti.</li><li>Trip ki ek hi currency tay kar lein.</li></ul>
<p><a class="btn" href="../auth/register.php">Group banayein (free)</a></p>
<?php ef_public_end();
