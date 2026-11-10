<?php
/**
 * Health check for people and uptime monitors. No session, no layout.
 *   /health.php        -> the web server and PHP are answering (does not touch the database)
 *   /health.php?db=1   -> also checks the database (3 second limit)
 * Always answers fast and is never saved by the service worker or the browser.
 * An outside monitor calling ?db=1 also fills the uptime history in Admin > Website health (one row per 4 minutes).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex');
header('Access-Control-Allow-Origin: *');
$out = ['ok' => true, 'time' => gmdate('c')];
if (isset($_GET['db'])) {
    define('EF_HEALTH', true); // config/db.php then reports a failure to us instead of showing an error page
    $out['db'] = false;
    ob_start();
    try {
        require_once __DIR__ . '/config/db.php';
        $t0 = microtime(true);
        $out['db'] = isset($conn) && $conn instanceof PDO && (bool) $conn->query('SELECT 1')->fetchColumn();
        if ($out['db']) {
            try {
                require_once __DIR__ . '/modules/health/lib.php';
                health_record($conn, 'ping', round((microtime(true) - $t0) * 1000, 1), true, 240);
            } catch (Throwable $e) {
                // the history is optional; the answer below must never fail because of it
            }
        }
    } catch (Throwable $e) {
        $out['db'] = false;
    }
    ob_end_clean();
    $out['ok'] = $out['db'];
    if (!$out['ok']) {
        http_response_code(503);
    }
}
echo json_encode($out);
