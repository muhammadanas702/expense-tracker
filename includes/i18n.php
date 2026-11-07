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
    if (defined('EF_ADMIN_PAGE')) {
        return $lang; // the staff panel is English only (nothing there is translated, so never half and half)
    }
    $pick = $_GET['lang'] ?? null;
    if (is_string($pick) && in_array($pick, ['en', 'ur'], true)) {
        $lang = $pick;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie('ef_lang', $pick, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        }
    } elseif (isset($_COOKIE['ef_lang']) && $_COOKIE['ef_lang'] === 'ur' && !defined('EF_LANG_DEFAULT_EN')) {
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


/** The Urdu dictionary (English text => Urdu), loaded once. */
function ef_ur_dictionary(): array
{
    static $ur = null;
    return $ur ??= require __DIR__ . '/lang/ur.php';
}

/** Looks one piece of visible text up. Whitespace is ignored when matching and kept around the result; "A · B" titles are translated part by part. */
function ef_i18n_piece(string $raw): string
{
    $plain = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $key = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    if ($key === '' || !preg_match('/[A-Za-z]{2}/', $key)) {
        return $raw;
    }
    $ur = ef_ur_dictionary();
    if (isset($ur[$key])) {
        $lead = preg_match('/^\s*/u', $raw, $m) ? $m[0] : '';
        $trail = preg_match('/\s*$/u', $raw, $m) ? $m[0] : '';
        return $lead . htmlspecialchars($ur[$key], ENT_NOQUOTES, 'UTF-8') . $trail;
    }
    if (strpos($key, ' · ') !== false) {
        $parts = explode(' · ', $key);
        $out = [];
        foreach ($parts as $part) {
            $out[] = $ur[$part] ?? $part;
        }
        $lead = preg_match('/^\s*/u', $raw, $m) ? $m[0] : '';
        $trail = preg_match('/\s*$/u', $raw, $m) ? $m[0] : '';
        return $lead . htmlspecialchars(implode(' · ', $out), ENT_NOQUOTES, 'UTF-8') . $trail;
    }
    return $raw;
}

/**
 * Output filter for Urdu pages: every visible text and the placeholder / title / aria-label / alt / button texts that match an entry of
 * includes/lang/ur.php exactly are replaced, so pages do not need t() around every word. Scripts, styles, comments and non-HTML answers
 * (JSON, files) are never touched.
 */
function ef_i18n_page(string $html): string
{
    if ($html === '' || strpos($html, '<') === false || $html[0] === '{' || $html[0] === '[') {
        return $html;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    $parts = preg_split('/(<script\b.*?<\/script>|<style\b.*?<\/style>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    foreach ($parts as $i => $seg) {
        if ($seg === '') {
            continue;
        }
        if ($seg[0] !== '<') {
            $parts[$i] = ef_i18n_piece($seg);
        } elseif (isset($seg[1]) && $seg[1] !== '/' && $seg[1] !== '!' && stripos($seg, '<script') !== 0 && stripos($seg, '<style') !== 0) {
            $isButton = (bool) preg_match('/<input\b[^>]*\btype=["\']?(submit|button|reset)/i', $seg);
            $parts[$i] = preg_replace_callback('/\b(placeholder|title|aria-label|alt|value)=("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($isButton) {
                if (strtolower($m[1]) === 'value' && !$isButton) {
                    return $m[0];
                }
                $val = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
                $new = ef_i18n_piece($val);
                return $new === $val ? $m[0] : $m[1] . '="' . str_replace('"', '&quot;', $new) . '"';
            }, $seg);
        }
    }
    return implode('', $parts);
}

if (PHP_SAPI !== 'cli' && ef_lang() === 'ur') {
    ob_start('ef_i18n_page');
}

/** "November 2026" in the active language (Urdu month names in Urdu mode). */
function ef_month_year(\DateTimeInterface $d): string
{
    if (ef_lang() !== 'ur') {
        return $d->format('F Y');
    }
    $m = ['جنوری','فروری','مارچ','اپریل','مئی','جون','جولائی','اگست','ستمبر','اکتوبر','نومبر','دسمبر'];
    return $m[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
}

/** Font style sheet + preloads, first in <head> so the right font is there before the first paint (no swap from another font). */
function ef_font_head(): string
{
    if (!function_exists('ef_asset')) {
        return '';
    }
    $h = fn(string $p) => htmlspecialchars(ef_asset($p), ENT_QUOTES, 'UTF-8');
    $pre = fn(string $p) => '<link rel="preload" href="' . htmlspecialchars((string) strtok(ef_asset($p), '?'), ENT_QUOTES, 'UTF-8') . '" as="font" type="font/woff2" crossorigin>';
    $out = $pre('assets/fonts/inter-latin.woff2');
    if (ef_dir() === 'rtl') {
        $out .= $pre('assets/fonts/noto-nastaliq-urdu-400.woff2') . $pre('assets/fonts/noto-nastaliq-urdu-700.woff2');
    } else {
        $out .= $pre('assets/fonts/plus-jakarta-sans-latin.woff2');
    }
    return $out . '<link rel="stylesheet" href="' . $h('assets/fonts.css') . '"><link rel="stylesheet" href="' . $h('assets/polish.css') . '">';
}
