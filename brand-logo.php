<?php
/** Serves the logo uploaded in Admin > Branding. Public, no session, cached by version (?v=). Falls back to the built-in logo. */
require_once __DIR__ . '/modules/branding/lib.php';
$f = brand_has_logo() ? BRAND_LOGO_FILE : null;
if ($f) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($f));
    readfile($f);
} else {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=3600');
    readfile(__DIR__ . '/' . BRAND_BUILTIN_LOGO);
}
