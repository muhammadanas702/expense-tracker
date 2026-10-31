<?php
/**
 * Router for PHP's built-in web server (local development and the Procfile).
 * The built-in server ignores .htaccess, so private files are blocked here instead.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$blocked = '#(^|/)(\.(?!well-known/)|vendor/|tcpdf/|includes/|modules/|config/|storage/|auth/src/)|\.(log|sql|md|lock|yml|yaml|bak|ini|sh)$|(^|/)(composer\.json|Dockerfile|Procfile|router\.php)$#i';

if (strpos($path, '..') !== false || preg_match($blocked, $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Unknown address: the same friendly 404 page the live site shows (Apache does this with ErrorDocument).
$file = __DIR__ . $path;
if ($path !== '/' && !is_file($file) && !is_dir($file)) {
    require_once __DIR__ . '/includes/errors.php';
    ef_error_page(404);
}

return false; // let the built-in server handle the request normally
