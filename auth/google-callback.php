<?php
// Step 2 of "Continue with Google": Google sends the person back here with a one-time code.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/oauth/lib.php';
require_once __DIR__ . '/../modules/security/lib.php';
require_once __DIR__ . '/../modules/settings/lib.php';

function google_fail(string $msg): void
{
    global $base_url;
    $_SESSION['reset_message'] = $msg;
    $_SESSION['reset_type'] = 'error';
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}

header('Cache-Control: no-store');
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/dashboard.php');
    exit();
}
if (google_settings() === null) {
    google_fail('Google sign-in is not available right now. Please use your email and password.');
}
if (security_ip_blocked($conn)) {
    google_fail('Signing in is not available from this connection right now. Please contact support.');
}

$saved = google_take_state((string) ($_GET['state'] ?? ''));
if ($saved === null) {
    google_fail('The Google sign-in expired. Please try again.');
}
if (!empty($_GET['error']) || empty($_GET['code']) || !is_string($_GET['code'])) {
    google_fail('Google sign-in was cancelled. You can try again or use your email and password.');
}

$ipKey = rl_key(client_ip());
if (rl_blocked($conn, 'google_cb_ip', $ipKey, 60, 3600)) {
    google_fail('Too many attempts. Please wait a while and try again.');
}
rl_hit($conn, 'google_cb_ip', $ipKey);

$claims = google_exchange_code((string) $_GET['code'], (string) $saved['verifier']);
$why = $claims === null ? 'exchange failed' : google_claims_error($claims, google_settings()['client_id']);
if ($why !== null) {
    error_log('ExpenseFlow Google sign-in refused: ' . $why);
    google_fail('We could not confirm your Google account. Please try again or use your email and password.');
}

[$user, $err] = google_user_for_claims($conn, $claims, settings_signups_open($conn));
if ($user === null) {
    $msgs = [
        'blocked' => 'This account has been blocked. Please contact support.',
        'closed' => 'New sign-ups are closed for now. Please try again later.',
    ];
    google_fail($msgs[$err] ?? 'Could not sign you in with Google. Please try again.');
}

session_after_login((int) $user['id'], (string) $user['name']);
if (!empty($user['created'])) {
    logAction($conn, (int) $user['id'], 'terms_accepted', 'Continued with Google and accepted the Terms of Use and Privacy Policy on ' . date('Y-m-d H:i'));
    logAction($conn, (int) $user['id'], 'signup_google', 'Account created with Google');
}
logAction($conn, (int) $user['id'], 'login', 'User logged in with Google');

$pending = $_SESSION['pending_join'] ?? '';
unset($_SESSION['pending_join']);
if (is_string($pending) && preg_match('/^[0-9a-f]{32}$/', $pending)) {
    header('Location: ' . $base_url . '/shared/join.php?code=' . $pending);
} else {
    header('Location: ' . $base_url . '/dashboard.php');
}
exit();
