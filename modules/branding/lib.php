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
const BRAND_TEXTS_FILE = __DIR__ . '/../../storage/brand-texts.json';
const BRAND_TEXT_MAX = 300;
const BRAND_TEXTS_MAX = 2000;
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
    return brand_is_custom() || $d['a1'] !== '' || $d['a2'] !== '' || brand_has_logo() || brand_texts() !== [];
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

/** Same whitespace rules as the Urdu filter, so one text has one key everywhere. */
function brand_norm(string $raw): string
{
    $plain = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $plain));
}

/** Edited texts: ['original English text' => ['en' => '...', 'ur' => '...']] (a language that was not edited is missing). */
function brand_texts(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $out = [];
    try {
        if (is_file(BRAND_TEXTS_FILE)) {
            $j = json_decode((string) file_get_contents(BRAND_TEXTS_FILE), true);
            foreach (is_array($j) ? $j : [] as $k => $v) {
                if (!is_string($k) || !is_array($v)) {
                    continue;
                }
                $row = [];
                foreach (['en', 'ur'] as $l) {
                    if (isset($v[$l]) && is_string($v[$l]) && $v[$l] !== '') {
                        $row[$l] = $v[$l];
                    }
                }
                if ($row) {
                    $out[$k] = $row;
                }
            }
        }
    } catch (Throwable $e) {
        // no edited texts
    }
    return $cache = $out;
}

/** Secret for the edit-mode cookie, created once in storage/. Null when storage is not writable. */
function brand_edit_key(): ?string
{
    $f = __DIR__ . '/../../storage/brand-edit.key';
    if (!is_file($f)) {
        @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    $k = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    return strlen($k) >= 32 ? $k : null;
}

/** Value of the edit-mode cookie for this staff member (signed, valid 8 hours), or null. */
function brand_edit_cookie(int $userId): ?string
{
    $k = brand_edit_key();
    if ($k === null) {
        return null;
    }
    $exp = time() + 8 * 3600;
    return $userId . '.' . $exp . '.' . hash_hmac('sha256', $userId . '.' . $exp, $k);
}

/** Edit mode: switched on by staff in Admin > Branding. A signed cookie, so public pages need no session to know it. Saving still checks the staff role. */
function brand_edit_on(): bool
{
    $c = (string) ($_COOKIE['ef_be'] ?? '');
    if (!preg_match('/^(\d+)\.(\d+)\.([0-9a-f]{64})$/', $c, $m) || (int) $m[2] < time()) {
        return false;
    }
    $k = brand_edit_key();
    return $k !== null && hash_equals(hash_hmac('sha256', $m[1] . '.' . $m[2], $k), $m[3]);
}

/** Which original text does what the visitor sees belong to? (the page may show an edited text or the Urdu translation) */
function brand_text_key(string $cur, string $lang): string
{
    foreach (brand_texts() as $k => $v) {
        if (($v[$lang] ?? null) === $cur) {
            return $k;
        }
    }
    if ($lang === 'ur') {
        $dict = function_exists('ef_ur_dictionary') ? ef_ur_dictionary() : [];
        $k = array_search($cur, $dict, true);
        if (is_string($k)) {
            return $k;
        }
    }
    return $cur;
}

/** Sets ($new text) or clears ($new === '') one language of one text. Returns an error text or null. */
function brand_text_set(string $key, string $lang, string $new): ?string
{
    $lang = $lang === 'ur' ? 'ur' : 'en';
    $new = brand_norm($new);
    $new = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $new);
    if (mb_strlen($new) > BRAND_TEXT_MAX) {
        return 'A text can be up to ' . BRAND_TEXT_MAX . ' characters.';
    }
    $all = brand_texts(true);
    $dict = function_exists('ef_ur_dictionary') ? ef_ur_dictionary() : [];
    $original = $lang === 'ur' ? ($dict[$key] ?? $key) : $key;
    if ($new === '' || $new === $original) {
        unset($all[$key][$lang]);
        if (empty($all[$key])) {
            unset($all[$key]);
        }
    } else {
        if (!isset($all[$key]) && count($all) >= BRAND_TEXTS_MAX) {
            return 'Too many edited texts (' . BRAND_TEXTS_MAX . ' at most). Reset some first.';
        }
        $all[$key][$lang] = $new;
    }
    $dir = dirname(BRAND_TEXTS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!$all) {
        return !is_file(BRAND_TEXTS_FILE) || @unlink(BRAND_TEXTS_FILE) ? null : 'Could not save (the storage folder is not writable).';
    }
    if (@file_put_contents(BRAND_TEXTS_FILE, json_encode($all, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        return 'Could not save (the storage folder is not writable).';
    }
    brand_texts(true);
    return null;
}

/** text => replacement for the language of this request (what the visitor would see now => what to show). */
function brand_text_map(string $lang): array
{
    $dict = $lang === 'ur' && function_exists('ef_ur_dictionary') ? ef_ur_dictionary() : [];
    $map = [];
    foreach (brand_texts() as $k => $v) {
        if ($lang === 'ur') {
            $to = $v['ur'] ?? ((!isset($dict[$k]) && isset($v['en'])) ? $v['en'] : null);
            if ($to === null) {
                continue;
            }
            $map[$k] = $to;
            if (isset($dict[$k])) {
                $map[$dict[$k]] = $to;
            }
        } elseif (isset($v['en'])) {
            $map[$k] = $v['en'];
        }
    }
    return $map;
}

function brand_apply_text(string $raw, array $map): string
{
    if (!$map || $raw === '') {
        return $raw;
    }
    $key = brand_norm($raw);
    if ($key === '' || !isset($map[$key])) {
        return $raw;
    }
    $lead = preg_match('/^\s*/u', $raw, $m) ? $m[0] : '';
    $trail = preg_match('/\s*$/u', $raw, $m) ? $m[0] : '';
    return $lead . htmlspecialchars($map[$key], ENT_NOQUOTES, 'UTF-8') . $trail;
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
    $staffPanel = defined('EF_ADMIN_PAGE');
    $lang = function_exists('ef_lang') ? ef_lang() : 'en';
    $map = $staffPanel ? [] : brand_text_map($lang);
    $swapName = brand_is_custom() && strpos($html, BRAND_BUILTIN) !== false;
    if ($swapName || $map) {
        $name = brand_name();
        $text = htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8');
        $attr = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $json = substr((string) json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
        $parts = preg_split('/(<script\b.*?<\/script>|<style\b.*?<\/style>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts !== false) {
            foreach ($parts as $i => $seg) {
                if ($seg === '') {
                    continue;
                }
                if ($seg[0] !== '<') {
                    $seg = brand_apply_text($seg, $map);
                    $parts[$i] = $swapName ? str_replace(BRAND_BUILTIN, $text, $seg) : $seg;
                } elseif (stripos($seg, '<script') === 0) {
                    if ($swapName && stripos($seg, 'application/ld+json') !== false) {
                        $parts[$i] = str_replace(BRAND_BUILTIN, $json, $seg);
                    }
                } elseif (isset($seg[1]) && $seg[1] !== '/' && $seg[1] !== '!' && stripos($seg, '<style') !== 0) {
                    $isMeta = stripos($seg, '<meta') === 0;
                    $isButton = (bool) preg_match('/<input\b[^>]*\btype=["\']?(submit|button|reset)/i', $seg);
                    $parts[$i] = preg_replace_callback('/\b(placeholder|title|aria-label|alt|content|value)=("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($isMeta, $isButton, $attr, $swapName, $map) {
                        $k = strtolower($m[1]);
                        if (($k === 'content' && !$isMeta) || ($k === 'value' && !$isButton)) {
                            return $m[0];
                        }
                        $val = $m[3] !== '' ? $m[3] : ($m[4] ?? '');
                        $new = $k === 'content' ? $val : brand_apply_text($val, $map);
                        if ($swapName) {
                            $new = str_replace(BRAND_BUILTIN, $attr, $new);
                        }
                        return $new === $val ? $m[0] : $m[1] . '="' . str_replace('"', '&quot;', $new) . '"';
                    }, $seg);
                }
            }
            $html = implode('', $parts);
        }
    }
    // Edit mode (staff only): the little editor that lets them click any text of any page.
    if (!$staffPanel && brand_edit_on() && function_exists('ef_url') && ($pos = strripos($html, '</body>')) !== false) {
        $tag = '<script src="' . htmlspecialchars(ef_url('assets/brand-edit.js'), ENT_QUOTES, 'UTF-8') . '?v=' . (int) @filemtime(__DIR__ . '/../../assets/brand-edit.js')
            . '" data-post="' . htmlspecialchars(ef_url('admin/brand-text-action.php'), ENT_QUOTES, 'UTF-8')
            . '" data-lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8')
            . '" data-back="' . htmlspecialchars(ef_url('admin/branding.php'), ENT_QUOTES, 'UTF-8') . '" defer></script>';
        $html = substr($html, 0, $pos) . $tag . substr($html, $pos);
    }
    return $html;
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
