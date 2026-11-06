<?php
/**
 * JSON endpoint for Phone alerts (Web Push). GET -> {key, devices, csrf}. POST {csrf, action: subscribe|unsubscribe|test, subscription:{endpoint, keys:{p256dh, auth}}}.
 * Needs a signed-in session and the CSRF token. Subscriptions belong to the signed-in user only.
 */
require_once __DIR__ . '/includes/security.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules.php';
if (!isset($_SESSION['user_id']) || !isset(ef_modules()['push']) || !is_file(__DIR__ . '/modules/push/lib.php')) {
    http_response_code(401);
    echo json_encode(['error' => 'unavailable']);
    exit();
}
require_once __DIR__ . '/modules/push/lib.php';
$uid = (int) $_SESSION['user_id'];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['key' => push_vapid($conn)[1], 'devices' => push_device_count($conn, $uid), 'csrf' => csrf_token()]);
        exit();
    }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 5000), true);
    if (!is_array($in) || !hash_equals(csrf_token(), (string) ($in['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit();
    }
    $key = rl_key((string) $uid);
    if (function_exists('rl_blocked') && rl_blocked($conn, 'push_sub', $key, 30, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'slow down']);
        exit();
    }
    if (function_exists('rl_hit')) {
        rl_hit($conn, 'push_sub', $key);
    }
    $sub = is_array($in['subscription'] ?? null) ? $in['subscription'] : [];
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $action = (string) ($in['action'] ?? '');
    if ($action === 'subscribe') {
        $ok = push_subscribe($conn, $uid, $endpoint, (string) ($sub['keys']['p256dh'] ?? ''), (string) ($sub['keys']['auth'] ?? ''));
        if (!$ok) {
            http_response_code(422);
            echo json_encode(['error' => 'invalid']);
            exit();
        }
    } elseif ($action === 'unsubscribe') {
        push_unsubscribe($conn, $uid, $endpoint);
    } elseif ($action === 'test') {
        $sent = push_notify($conn, [$uid], 'ExpenseFlow', 'Phone alerts are on. You will see shared bills, reminders and budget alerts here.', 'dashboard.php');
        echo json_encode(['sent' => $sent, 'devices' => push_device_count($conn, $uid)]);
        exit();
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'action']);
        exit();
    }
    echo json_encode(['ok' => true, 'devices' => push_device_count($conn, $uid)]);
} catch (Throwable $e) {
    error_log('ExpenseFlow push endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server']);
}
