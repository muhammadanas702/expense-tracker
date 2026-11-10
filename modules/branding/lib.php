<?php
/**
 * Branding module (Part 100a): the site name, tagline, two brand colours and the logo, editable in Admin > Branding.
 * Stored in storage/branding.json (no database, no SQL) so public pages keep their name during an outage.
 * Nothing in the pages has to change when the name changes: ef_brand_page() swaps the built-in name "ExpenseFlow" in the
 * finished HTML (visible texts, alt/title/aria-label/placeholder, meta tags and the JSON-LD data); e-mails use brand_text().
 * While the name is the default, nothing is touched at all.
 */
const BRAND_FILE = __DIR__ . '/../../storage/branding.json';
const BRAND_BUILTIN = 'ExpenseFlow';
const BRAND_DEFAULT_TAGLINE = 'A simple, private way to track spending, set budgets and share expenses with friends.';
const BRAND_LOGO_FILE = __DIR__ . '/../../storage/brand-logo.png';
const BRAND_BUILTIN_LOGO = 'assets/icons/logo-96.webp';
const BRAND_DEFAULT_A1 = '#0f766e';
const BRAND_DEFAULT_A2 = '#1d4ed8';
const BRAND_LOGO_MAX_BYTES = 1500000;
const BRAND_NAME_MAX = 30;
const BRAND_TAGLINE_MAX = 140;

/** Saved branding merged over the defaults. Cached per request; never throws. */
function brand_data(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $out = ['name' => BRAND_BUILTIN, 'tagline' => BRAND_DEFAULT_TAGLINE, 'a1' => '', 'a2' => ''];
    try {
        if (is_file(BRAND_FILE)) {
            $j = json_decode((string) file_get_contents(BRAND_FILE), true);
            if (is_array($j)) {
                foreach (['name', 'tagline'] as $k) {
                    if (isset($j[$k]) && is_string($j[$k]) && trim($j[$k]) !== '') {
                        $out[$k] = trim($j[$k]);
                    }
                }
                foreach (['a1', 'a2'] as $k) {
                    if (isset($j[$k]) && is_string($j[$k]) && preg_match('/^#[0-9a-f]{6}$/i', $j[$k])) {
                        $out[$k] = strtolower($j[$k]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // keep the defaults
    }
    return $cache = $out;
}

function brand_name(): string
{
    return brand_data()['name'];
}

function brand_tagline(): string
{
    return brand_data()['tagline'];
}

function brand_is_custom(): bool
{
    return brand_name() !== BRAND_BUILTIN;
}

function brand_has_logo(): bool
{
    return is_file(BRAND_LOGO_FILE);
}

/** True when anything differs from the built-in look; while false, no page is touched. */
function brand_active(): bool
{
    $d = brand_data();
    return brand_is_custom() || $d['a1'] !== '' || $d['a2'] !== '' || brand_has_logo();
}

/** Address of the uploaded logo (relative to the site root), with a version so a new upload shows at once. */
function brand_logo_path(): string
{
    return 'brand-logo.php?v=' . (int) @filemtime(BRAND_LOGO_FILE);
}

/** Contrast ratio of a #rrggbb colour against white (WCAG). Buttons carry white text, so this must stay readable. */
function brand_contrast(string $hex): float
{
    $c = [];
    foreach ([1, 3, 5] as $i) {
        $v = hexdec(substr($hex, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
    }
    $l = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    return 1.05 / ($l + 0.05);
}

/** Plain text (e-mails, subjects): the built-in name becomes the chosen name. */
function brand_text(string $s): string
{
    return brand_is_custom() ? str_replace(BRAND_BUILTIN, brand_name(), $s) : $s;
}

/** Output filter for HTML pages. Scripts and styles are left alone, except JSON-LD where the name is JSON-escaped. */
function ef_brand_page(string $html): string
{
    if ($html === '' || strpos($html, '<') === false || $html[0] === '{' || $html[0] === '[') {
        return $html;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;
        }
    }
    $d = brand_data();
    // Logo: every place that shows the built-in logo file shows the uploaded one instead.
    if (brand_has_logo()) {
        $html = str_replace(BRAND_BUILTIN_LOGO, brand_logo_path(), $html);
    }
    // Colours: one small style block at the end of <head>, so it wins over the site style sheets.
    if ($d['a1'] !== '' || $d['a2'] !== '') {
        $a1 = $d['a1'] !== '' ? $d['a1'] : BRAND_DEFAULT_A1;
        $a2 = $d['a2'] !== '' ? $d['a2'] : BRAND_DEFAULT_A2;
        $css = '<style id="brand-colours">:root,[data-mode=s]{--a1:' . $a1 . ';--a2:' . $a2 . ';--h2:' . $a1 . '}</style>';
        $html = preg_replace('~<meta name="theme-color" content="#[0-9a-fA-F]{6}">~', '<meta name="theme-color" content="' . $a1 . '">', $html, 1);
        $pos = stripos($html, '</head>');
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $css . substr($html, $pos);
        }
    }
    if (!brand_is_custom() || strpos($html, BRAND_BUILTIN) === false) {
        return $html;
    }
    $name = brand_name();
    $text = htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8');
    $attr = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $json = substr((string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
    $parts = preg_split('/(<script\b.*?<\/script>|<style\b.*?<\/style>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return $html;
    }
    foreach ($parts as $i => $seg) {
        if ($seg === '') {
            continue;
        }
        if ($seg[0] !== '<') {
            $parts[$i] = str_replace(BRAND_BUILTIN, $text, $seg);
        } elseif (stripos($seg, '<script') === 0) {
            if (stripos($seg, 'application/ld+json') !== false) {
                $parts[$i] = str_replace(BRAND_BUILTIN, $json, $seg);
            }
        } elseif (isset($seg[1]) && $seg[1] !== '/' && $seg[1] !== '!' && stripos($seg, '<style') !== 0) {
            $isMeta = stripos($seg, '<meta') === 0;
            $parts[$i] = preg_replace_callback('/\b(placeholder|title|aria-label|alt|content)=("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($isMeta, $attr) {
                if (strtolower($m[1]) === 'content' && !$isMeta) {
                    return $m[0];
                }
                $val = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
                return strpos($val, BRAND_BUILTIN) === false ? $m[0] : $m[1] . '="' . str_replace(BRAND_BUILTIN, $attr, $val) . '"';
            }, $seg);
        }
    }
    return implode('', $parts);
}

/** Staff form. Returns [values|null, error|null]. An empty field means "back to the default". */
function brand_validate(array $in): array
{
    $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
    if ($name === '') {
        $name = BRAND_BUILTIN;
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > BRAND_NAME_MAX || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} &\'.\-]*$/u', $name)) {
        return [null, 'The site name needs 2 to ' . BRAND_NAME_MAX . ' characters: letters, numbers, spaces and & \' . - only.'];
    }
    $tag = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['tagline'] ?? '')));
    if ($tag === '') {
        $tag = BRAND_DEFAULT_TAGLINE;
    }
    if (mb_strlen($tag) > BRAND_TAGLINE_MAX || preg_match('/[<>]/', $tag)) {
        return [null, 'The tagline can be up to ' . BRAND_TAGLINE_MAX . ' characters and cannot contain < or >.'];
    }
    $col = [];
    foreach (['a1' => 'main', 'a2' => 'second'] as $k => $label) {
        $v = strtolower(trim((string) ($in[$k] ?? '')));
        if ($v === '' || $v === ($k === 'a1' ? BRAND_DEFAULT_A1 : BRAND_DEFAULT_A2)) {
            $col[$k] = '';
            continue;
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
            return [null, 'The ' . $label . ' colour must look like #0f766e.'];
        }
        if (brand_contrast($v) < 4.5) {
            return [null, 'The ' . $label . ' colour is too light: white text on it would be hard to read. Pick a darker one.'];
        }
        $col[$k] = $v;
    }
    return [['name' => $name, 'tagline' => $tag, 'a1' => $col['a1'], 'a2' => $col['a2']], null];
}

/** Saves the file. Returns what changed (empty = nothing); false when the file cannot be written. */
function brand_save(array $vals): array|false
{
    $now = brand_data(true);
    $changes = [];
    if ($now['name'] !== $vals['name']) {
        $changes[] = 'site name "' . $now['name'] . '" to "' . $vals['name'] . '"';
    }
    if ($now['tagline'] !== $vals['tagline']) {
        $changes[] = 'tagline changed';
    }
    foreach (['a1' => 'main colour', 'a2' => 'second colour'] as $k => $label) {
        if (($now[$k] ?? '') !== ($vals[$k] ?? '')) {
            $changes[] = $label . ' ' . ($vals[$k] !== '' ? 'set to ' . $vals[$k] : 'back to default');
        }
    }
    if (!$changes) {
        return [];
    }
    $dir = dirname(BRAND_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $data = ['name' => $vals['name'] === BRAND_BUILTIN ? '' : $vals['name'], 'tagline' => $vals['tagline'] === BRAND_DEFAULT_TAGLINE ? '' : $vals['tagline'], 'a1' => $vals['a1'], 'a2' => $vals['a2']];
    if (@file_put_contents(BRAND_FILE, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        return false;
    }
    brand_data(true);
    return $changes;
}


/** Stores an uploaded logo as a 256x256 PNG (any shape fits inside, transparent around it). Returns an error text or null. */
function brand_save_logo(array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? '')) && empty($f['_test'])) {
        return 'The logo did not upload. Try a smaller file.';
    }
    if ((int) $f['size'] > BRAND_LOGO_MAX_BYTES) {
        return 'The logo is too big (1.5 MB at most).';
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return 'The logo must be a PNG, JPG or WebP picture.';
    }
    if ($info[0] < 64 || $info[1] < 64 || $info[0] > 4096 || $info[1] > 4096) {
        return 'The logo must be between 64 and 4096 pixels wide and high.';
    }
    if (!function_exists('imagecreatefromstring')) {
        return 'This server cannot process pictures (the GD extension is missing).';
    }
    $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
    if (!$src) {
        return 'The picture could not be read.';
    }
    $size = 256;
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    $w = imagesx($src);
    $h = imagesy($src);
    $r = min($size / $w, $size / $h);
    $nw = max(1, (int) round($w * $r));
    $nh = max(1, (int) round($h * $r));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
    imagealphablending($dst, false);
    $dir = dirname(BRAND_LOGO_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ok = @imagepng($dst, BRAND_LOGO_FILE, 9);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? null : 'The logo could not be saved (the storage folder is not writable).';
}

function brand_remove_logo(): bool
{
    return !is_file(BRAND_LOGO_FILE) || @unlink(BRAND_LOGO_FILE);
}
