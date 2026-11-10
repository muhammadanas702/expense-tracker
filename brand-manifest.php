<?php
/** The web-app manifest with the name, colour and icons from Admin > Branding. Public, no session. */
require_once __DIR__ . '/modules/branding/lib.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode(brand_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
