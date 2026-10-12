<?php
/**
 * Router for PHP's built-in web server (local development and the Procfile).
 * The built-in server ignores .htaccess, so private files are blocked here instead.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$blocked = '#(^|/)(\.|vendor/|tcpdf/|includes/|config/|storage/|auth/src/)|\.(log|sql|md|lock|yml|yaml|bak|ini|sh)$|(^|/)(composer\.json|Dockerfile|Procfile|router\.php)$#i';

if (strpos($path, '..') !== false || preg_match($blocked, $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

return false; // let the built-in server handle the request normally
