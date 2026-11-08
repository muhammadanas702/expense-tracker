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
require_once __DIR__ . '/site-chrome.php';

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
<html<?= ef_html_attrs() ?>>
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
<meta name="theme-color" content="#0f766e">
<link rel="stylesheet" href="<?= e(ef_asset('assets/site.css')) ?>">
<link rel="stylesheet" href="<?= e(ef_asset('assets/public.css')) ?>">
<?= ef_font_head() . ef_rtl_link() ?>
<?php require_once __DIR__ . '/theme.php'; echo ef_theme_head(); ?>
<?php foreach ($schemas as $s): ?>
<script type="application/ld+json"><?= json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
</head>
<body class="s-body">
<?= ef_site_nav($base, false) ?>
<main id="main">
<div class="s-wrap p-top">
<nav class="p-crumbs" aria-label="Breadcrumb"><a href="<?= e($base) ?>/">Home</a><?php foreach (($o['crumbs'] ?? []) as $c): ?><span>/</span><a href="<?= e($base . '/' . ltrim($c[1], '/')) ?>"><?= e($c[0]) ?></a><?php endforeach; ?></nav>
<h1 class="p-h1"><?= e((string) ($o['h1'] ?? $title)) ?></h1>
</div>
<div class="s-wrap"><article class="p-doc">
<?php
}

function ef_public_end(): void
{
    $base = $GLOBALS['__ef_pub']['base'] ?? '';
    ?></article></div>
<div class="s-wrap"><section class="p-cta">
  <h2>Ready to take control?</h2>
  <p>Create your account in under a minute.</p>
  <a class="s-btn s-white s-lg" href="<?= e($base) ?>/auth/register.php">Get started <?= ef_icon('arr') ?></a>
</section></div>
</main>
<?= ef_site_footer($base) ?>
<script src="<?= e($base) ?>/assets/site.js" defer></script>
</body>
</html>
<?php
}
