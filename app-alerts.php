<?php
/**
 * JSON endpoint for the Android app's alerts (Part 130).
 *   signed-in page:  GET -> {csrf, devices};  POST {csrf, action:'register'} -> {token} (shown once, stored on the phone)
 *   the phone:       POST {token, action:'poll'} -> {items:[{id,title,body,url}]}   (no session, no cookie)
 */
require_once __DIR__ . '/includes/security.php';
$raw = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) file_get_contents('php://input', false, null, 0, 4000) : '';
$in = $raw !== '' ? json_decode($raw, true) : null;
$token = is_array($in) && is_string($in['token'] ?? null) ? $in['token'] : '';
if ($token === '') {
    secure_session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules.php';
if (($token === '' && !isset($_SESSION['user_id'])) || !isset(ef_modules()['push']) || !is_file(__DIR__ . '/modules/push/app.php')) {
    http_response_code(401);
    echo json_encode(['error' => 'unavailable']);
    exit();
}
require_once __DIR__ . '/modules/push/app.php';
try {
    if ($token !== '') {
        if (function_exists('rl_blocked') && rl_blocked($conn, 'app_poll', hash('sha256', $token), 60, 3600)) {
            http_response_code(429);
            echo json_encode(['error' => 'slow down']);
            exit();
        }
        if (function_exists('rl_hit')) {
            rl_hit($conn, 'app_poll', hash('sha256', $token));
        }
        [$uid] = app_device_user($conn, $token);
        if ($uid === 0 || ($in['action'] ?? '') !== 'poll') {
            http_response_code(401);
            echo json_encode(['error' => 'token']);
            exit();
        }
        echo json_encode(['items' => app_inbox_take($conn, $uid)], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $uid = (int) $_SESSION['user_id'];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['csrf' => csrf_token()]);
        exit();
    }
    if (!is_array($in) || !hash_equals(csrf_token(), (string) ($in['csrf'] ?? '')) || ($in['action'] ?? '') !== 'register') {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit();
    }
    if (function_exists('rl_blocked') && rl_blocked($conn, 'app_reg', rl_key((string) $uid), 10, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'slow down']);
        exit();
    }
    if (function_exists('rl_hit')) {
        rl_hit($conn, 'app_reg', rl_key((string) $uid));
    }
    echo json_encode(['token' => app_device_token_new($conn, $uid, ef_lang())]);
} catch (Throwable $e) {
    error_log('ExpenseFlow app alerts failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server']);
}
