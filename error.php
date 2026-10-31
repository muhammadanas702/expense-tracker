<?php
// Target of the Apache ErrorDocument lines in .htaccess: shows a friendly page for 400/403/404/500.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/errors.php';
$code = (int) ($_GET['c'] ?? ($_SERVER['REDIRECT_STATUS'] ?? 404));
if (!in_array($code, [400, 403, 404, 405, 429, 500, 503], true)) {
    $code = 404;
}
ef_error_page($code);
