<?php
/** Serves a picture uploaded in Admin > Branding (?dash=, ?group= or ?share=<version>). Public, no session. Falls back to the built-in picture. */
require_once __DIR__ . '/modules/branding/lib.php';
$slot = '';
foreach (array_keys(BRAND_IMAGE_SLOTS) as $k) {
    if (isset($_GET[$k])) {
        $slot = $k;
        break;
    }
}
if ($slot === '') {
    http_response_code(404);
    exit();
}
$f = brand_image_file($slot);
$types = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
if ($f !== null) {
    header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
    header('Cache-Control: public, max-age=31536000, immutable');
} else {
    $f = __DIR__ . '/' . BRAND_IMAGE_SLOTS[$slot]['builtin'];
    header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
    header('Cache-Control: public, max-age=3600');
}
header('Content-Length: ' . filesize($f));
readfile($f);
