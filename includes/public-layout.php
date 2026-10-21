<?php
/**
 * Layout for public information pages (About, Privacy, Terms, Contact, Guides): same look as the landing page,
 * with the meta tags search engines and link previews need.
 *   ef_public_start(['title'=>..., 'desc'=>..., 'path'=>'about.php', 'h1'=>..., 'schema'=>[...optional JSON-LD arrays]]);
 *   ... content ...
 *   ef_public_end();
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/assets.php';

function ef_public_start(array $o): void
{
    global $base_url;
    send_security_headers();
    $base = rtrim((string) $base_url, '/');
    $title = (string) $o['title'];
    $desc = (string) $o['desc'];
    $canonical = $base . '/' . ltrim((string) ($o['path'] ?? ''), '/');
    $img = $base . '/assets/og-image.png';
    $GLOBALS['__ef_pub'] = ['base' => $base];
    $schemas = $o['schema'] ?? [];
    $crumbs = [['name' => 'ExpenseFlow', 'url' => $base . '/']];
    foreach (($o['crumbs'] ?? []) as $c) {
        $crumbs[] = ['name' => $c[0], 'url' => $base . '/' . ltrim($c[1], '/')];
    }
    $crumbs[] = ['name' => (string) ($o['h1'] ?? $title), 'url' => $canonical];
    $schemas[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(
        fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url']],
        $crumbs, array_keys($crumbs)
    )];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php require __DIR__ . '/pwa-head.php'; ?>
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="<?= !empty($o['article']) ? 'article' : 'website' ?>">
<meta property="og:site_name" content="ExpenseFlow">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e($img) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($desc) ?>">
<meta name="twitter:image" content="<?= e($img) ?>">
<link rel="stylesheet" href="<?= e(ef_asset('assets/app.css')) ?>">
<?php foreach ($schemas as $s): ?>
<script type="application/ld+json"><?= json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
</head>
<body>
<div class="fr" data-mode="p">
<header class="g nb">
  <a class="logo" href="<?= e($base) ?>/" style="padding:0"><span class="logo"><img src="<?= e($base) ?>/assets/icons/icon-192.png" alt="" width="38" height="38"><span class="wm">ExpenseFlow</span></span></a>
  <nav class="lk" aria-label="Main"><a href="<?= e($base) ?>/#features">Features</a><a href="<?= e($base) ?>/guides/">Guides</a><a href="<?= e($base) ?>/about.php">About</a><a href="<?= e($base) ?>/#apps">Get the app</a></nav>
  <a class="lnk" href="<?= e($base) ?>/auth/login.php">Log in</a>
  <a class="btn" href="<?= e($base) ?>/auth/register.php">Get started</a>
</header>
<main class="sec pub">
<h1><?= e((string) ($o['h1'] ?? $title)) ?></h1>
<?php
}

function ef_public_end(): void
{
    $base = $GLOBALS['__ef_pub']['base'] ?? '';
    ?></main>
<footer class="fo"><span class="logo"><img src="<?= e($base) ?>/assets/icons/icon-192.png" alt="" width="38" height="38"><span class="wm">ExpenseFlow</span></span><span>© <?= date('Y') ?> ExpenseFlow</span>
  <span class="lk2"><a href="<?= e($base) ?>/about.php">About</a><a href="<?= e($base) ?>/guides/">Guides</a><a href="<?= e($base) ?>/privacy.php">Privacy</a><a href="<?= e($base) ?>/terms.php">Terms</a><a href="<?= e($base) ?>/contact.php">Contact</a><a href="<?= e($base) ?>/auth/login.php">Log in</a></span></footer>
</div>
</body>
</html>
<?php
}
