<?php
/** Shared top bar and footer for the landing page and the public pages (one look everywhere). Part 89. */
require_once __DIR__ . '/icons.php';

function ef_site_logo(string $base): string
{
    return '<a class="s-logo" href="' . e($base) . '/" aria-label="ExpenseFlow"><img src="' . e($base) . '/assets/icons/logo-96.webp" alt="" width="36" height="36"><span>ExpenseFlow</span></a>';
}

/** Footer blurb: the built-in text, or the tagline chosen in Admin > Branding followed by the privacy promise. */
function ef_site_tagline(): string
{
    require_once __DIR__ . '/../modules/branding/lib.php';
    $never = 'ExpenseFlow never asks for your bank or card details.';
    if (brand_tagline() === BRAND_DEFAULT_TAGLINE) {
        return 'A simple, private way to track spending, set budgets and share expenses with friends. ' . $never;
    }
    return e(brand_tagline()) . ' ' . (function_exists('t') ? e(t($never)) : $never);
}

/** $home = true on the landing page itself (anchors stay on the page). */
function ef_site_nav(string $base, bool $home = false): string
{
    $h = $home ? '' : e($base) . '/';
    $links = [['features', 'Features'], ['how', 'How it works'], ['apps', 'Get the app'], ['faq', 'FAQ']];
    $l = '';
    foreach ($links as $k) {
        $l .= '<a href="' . $h . '#' . $k[0] . '">' . e($k[1]) . '</a>';
    }
    $sw = function_exists('ef_lang_switch') ? ef_lang_switch('s-lang') : '';
    return '<a class="s-skip" href="#main">Skip to content</a><header class="s-nav" id="nav"><div class="s-wrap s-navin">'
        . ef_site_logo($base)
        . '<nav class="s-links" aria-label="Sections">' . $l . '</nav>'
        . '<div class="s-act">' . $sw . '<a class="s-btn s-ghost s-hide" href="' . e($base) . '/auth/login.php">Log in</a><a class="s-btn" href="' . e($base) . '/auth/register.php">Get started</a>'
        . '<button class="s-burger" id="burger" type="button" aria-expanded="false" aria-controls="menu" aria-label="Menu"><i></i><i></i><i></i></button></div>'
        . '</div><div class="s-menu" id="menu" hidden><div class="s-wrap">' . $l
        . '<a href="' . e($base) . '/auth/login.php">Log in</a></div></div></header>';
}

function ef_site_footer(string $base): string
{
    $b = e($base);
    return '<footer class="s-foot"><div class="s-wrap"><div class="s-fgrid">'
        . '<div class="s-fbrand">' . ef_site_logo($base) . '<p>' . ef_site_tagline() . '</p></div>'
        . '<nav aria-label="Product"><h3>Product</h3><a href="' . $b . '/#features">Features</a><a href="' . $b . '/#modes">Personal &amp; Shared</a><a href="' . $b . '/#how">How it works</a><a href="' . $b . '/#apps">Get the app</a><a href="' . $b . '/#faq">FAQ</a></nav>'
        . (ef_feature_on('guides') ? '<nav aria-label="Learn"><h3>Learn</h3><a href="' . $b . '/guides/">All guides</a><a href="' . $b . '/guides/track-monthly-expenses.php">Track monthly expenses</a><a href="' . $b . '/guides/budget-50-30-20-rule.php">The 50/30/20 budget rule</a></nav>' : '')
        . '<nav aria-label="Company"><h3>Company</h3><a href="' . $b . '/about.php">About</a><a href="' . $b . '/contact.php">Contact &amp; support</a><a href="' . $b . '/privacy.php">Privacy Policy</a><a href="' . $b . '/terms.php">Terms of Use</a></nav>'
        . '</div><div class="s-fbar"><span>© ' . date('Y') . ' ExpenseFlow. All rights reserved.</span><a href="#top">Back to top</a></div></div></footer>';
}
