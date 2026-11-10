<?php
/** Icon made from the logo uploaded in Admin > Branding (?i=<size>.<version>, or m<size> for the maskable home-screen shape). Public, no session. */
require_once __DIR__ . '/modules/branding/lib.php';
if (!preg_match('~^(m?)(\d{2,3})(?:\.\d+)?$~', (string) ($_GET['i'] ?? ''), $m)) {
    http_response_code(404);
    exit();
}
$png = brand_icon_png((int) $m[2], $m[1] === 'm');
if ($png === null) {
    // No uploaded logo (or no GD): the built-in icon of the nearest size.
    $built = [32 => 'favicon-32', 48 => 'favicon-48', 96 => 'favicon-48', 180 => 'apple-touch-icon', 192 => 'icon-192', 512 => 'icon-512'][(int) $m[2]] ?? 'icon-192';
    $f = __DIR__ . '/assets/icons/' . ($m[1] === 'm' ? 'maskable-' . ((int) $m[2] > 192 ? '512' : '192') : $built) . '.png';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($f);
    exit();
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . strlen($png));
echo $png;
