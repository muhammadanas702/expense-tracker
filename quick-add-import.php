<?php
/**
 * JSON endpoint for the Android app: GET gives {auto, csrf, since}, POST {action:'import', messages:[...]} adds payments
 * automatically (classifier in modules/quickadd/lib.php). Needs a signed-in session and the CSRF token.
 */
require_once __DIR__ . '/includes/security.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules.php';
if (!isset($_SESSION['user_id']) || !isset(ef_modules()['quickadd']) || !is_file(__DIR__ . '/modules/quickadd/lib.php')) {
    http_response_code(401);
    echo json_encode(['error' => 'unavailable']);
    exit();
}
require_once __DIR__ . '/modules/quickadd/lib.php';
$uid = (int) $_SESSION['user_id'];
try {
    $prefs = quickadd_prefs($conn, $uid);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['auto' => $prefs['auto'], 'since' => $prefs['since'] ? strtotime((string) $prefs['since']) * 1000 : 0, 'csrf' => csrf_token()]);
        exit();
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 200000), true);
    $tok = (string) ($in['csrf'] ?? '');
    if (!is_array($in) || !hash_equals(csrf_token(), $tok)) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit();
    }
    $key = rl_key((string) $uid);
    if (function_exists('rl_blocked') && rl_blocked($conn, 'sms_import', $key, 60, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'slow down']);
        exit();
    }
    if (function_exists('rl_hit')) {
        rl_hit($conn, 'sms_import', $key);
    }
    if (($in['action'] ?? '') === 'import' && is_array($in['messages'] ?? null)) {
        echo json_encode(quickadd_import($conn, $uid, $in['messages']));
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'bad request']);
    }
} catch (Throwable $e) {
    error_log('ExpenseFlow SMS import endpoint: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'failed']);
}
