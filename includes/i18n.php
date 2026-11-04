<?php
/**
 * Languages: English (default) and Urdu (right-to-left).
 * The choice is kept in the cookie `ef_lang` on THIS device (like the theme) and can be set with ?lang=ur / ?lang=en.
 * t('English text') returns the Urdu text from includes/lang/ur.php when Urdu is on and a translation exists;
 * anything not translated yet simply stays English, so a page is never broken by a missing word.
 */
function ef_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $lang = 'en';
    $pick = $_GET['lang'] ?? null;
    if (is_string($pick) && in_array($pick, ['en', 'ur'], true)) {
        $lang = $pick;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('ef_lang', $pick, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        }
    } elseif (isset($_COOKIE['ef_lang']) && $_COOKIE['ef_lang'] === 'ur') {
        $lang = 'ur';
    }
    return $lang;
}

function ef_dir(): string
{
    return ef_lang() === 'ur' ? 'rtl' : 'ltr';
}

/** ' lang="ur" dir="rtl"' for the <html> tag. */
function ef_html_attrs(): string
{
    return ' lang="' . ef_lang() . '" dir="' . ef_dir() . '"';
}

/** Translates $en (the English text is the key). Extra %s placeholders are filled with sprintf. */
function t(string $en, string ...$args): string
{
    static $ur = null;
    $out = $en;
    if (ef_lang() === 'ur') {
        $ur ??= require __DIR__ . '/lang/ur.php';
        $out = $ur[$en] ?? $en;
    }
    return $args ? vsprintf($out, $args) : $out;
}

/** The extra style sheet for right-to-left pages (empty for English). */
function ef_rtl_link(): string
{
    if (ef_dir() !== 'rtl' || !function_exists('ef_asset')) {
        return '';
    }
    return '<link rel="stylesheet" href="' . htmlspecialchars(ef_asset('assets/rtl.css'), ENT_QUOTES, 'UTF-8') . '">';
}

/** Address of the CURRENT page in the other language (keeps the other query values, for example an invite code). */
function ef_lang_url(string $to): string
{
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
    $q = $_GET;
    $q['lang'] = $to === 'ur' ? 'ur' : 'en';
    return $path . '?' . http_build_query($q);
}

/** A small link that switches to the other language ("اردو" on English pages, "English" on Urdu pages). */
function ef_lang_switch(string $class = 'lang-sw'): string
{
    $to = ef_lang() === 'ur' ? 'en' : 'ur';
    return '<a class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars(ef_lang_url($to), ENT_QUOTES, 'UTF-8')
        . '" hreflang="' . $to . '" lang="' . $to . '">' . ($to === 'ur' ? 'اردو' : 'English') . '</a>';
}

