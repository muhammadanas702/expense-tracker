<?php
// JSON endpoint used by the Android / Windows apps (assets/google-app.js): start a hand-off and poll for the result.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/oauth/lib.php';
require_once __DIR__ . '/../modules/security/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ha_out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d);
    exit();
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_valid()) {
    ha_out(['state' => 'error', 'message' => 'Please reload the page and try again.'], 403);
}
if (!google_configured() || !google_in_app_shell()) {
    ha_out(['state' => 'error', 'message' => 'Google sign-in is not available here. Use your email and password.'], 400);
}
try {
    if (security_ip_blocked($conn)) {
        ha_out(['state' => 'error', 'message' => 'Signing in is not available from this connection right now.'], 403);
    }
    $ipKey = rl_key(client_ip());
    if (($_POST['do'] ?? '') === 'start') {
        if (rl_blocked($conn, 'google_app_start', $ipKey, 20, 3600)) {
            ha_out(['state' => 'error', 'message' => 'Too many attempts. Please wait a while and try again.'], 429);
        }
        rl_hit($conn, 'google_app_start', $ipKey);
        [$id, $secret] = handoff_create($conn, (string) ($_POST['ret'] ?? ''));
        ha_out(['state' => 'started', 'id' => $id, 'url' => ef_url('auth/google.php?app=' . $id), 'secret' => $secret, 'ttl' => HANDOFF_TTL]);
    }
    if (($_POST['do'] ?? '') === 'poll') { // only reports progress; signing in needs the proof (do=redeem)
        if (rl_blocked($conn, 'google_app_poll', $ipKey, 600, 3600)) {
            ha_out(['state' => 'error', 'message' => 'Too many checks. Please try again in a while.'], 429);
        }
        rl_hit($conn, 'google_app_poll', $ipKey);
        $st = handoff_status($conn, (string) ($_POST['secret'] ?? ''));
        ha_out($st === 'gone' ? ['state' => 'gone', 'message' => 'The sign-in was cancelled or expired. Please try again.'] : ['state' => $st]);
    }
    if (($_POST['do'] ?? '') === 'redeem') { // the browser brought the proof back to this device
        if (rl_blocked($conn, 'google_app_redeem', $ipKey, 30, 3600)) {
            ha_out(['state' => 'error', 'message' => 'Too many attempts. Please try again in a while.'], 429);
        }
        rl_hit($conn, 'google_app_redeem', $ipKey);
        $uid = handoff_redeem($conn, (string) ($_POST['id'] ?? ''), (string) ($_POST['secret'] ?? ''), (string) ($_POST['proof'] ?? ''));
        if ($uid === null) {
            ha_out(['state' => 'gone', 'message' => 'The sign-in could not be completed or has expired. Please try again.']);
        }
        $st = $conn->prepare('SELECT id, name, blocked FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && (int) $u['blocked'] !== 1) {
            session_after_login((int) $u['id'], (string) $u['name']);
            ha_out(['state' => 'ok', 'url' => ef_url(google_landing_path($conn, (int) $u['id']))]);
        }
        ha_out(['state' => 'error', 'message' => 'This account has been blocked. Please contact support.']);
    }
    ha_out(['state' => 'error', 'message' => 'Unknown request.'], 400);
} catch (Throwable $e) {
    error_log('ExpenseFlow Google app hand-off: ' . $e->getMessage());
    ha_out(['state' => 'error', 'message' => 'Something went wrong. Please use your email and password for now.'], 500);
}
