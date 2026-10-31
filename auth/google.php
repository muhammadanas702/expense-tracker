<?php
// Step 1 of "Continue with Google": send the browser to Google (state + PKCE kept in the session).
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/oauth/lib.php';
require_once __DIR__ . '/../modules/security/lib.php';

function google_back(string $msg): void
{
    global $base_url;
    $_SESSION['reset_message'] = $msg;
    $_SESSION['reset_type'] = 'error';
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}

oauth_ensure_schema($conn);
$appId = (string) ($_GET['app'] ?? ''); // set when the Android / Windows app sent the person here (see "App hand-off")
if ($appId !== '' && (google_in_app_shell() || handoff_waiting($conn, $appId) === null)) {
    google_back('This sign-in link has expired. Please press "Continue with Google" in the app again.');
}
if ($appId === '' && !empty($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/dashboard.php');
    exit();
}
if (!google_configured() || ($appId === '' && !google_available())) {
    google_back(google_in_app_shell()
        ? 'Google sign-in is not available inside the app. Please log in with your email and password.'
        : 'Google sign-in is not available right now. Please use your email and password.');
}
if (security_ip_blocked($conn)) {
    google_back('Signing in is not available from this connection right now. Please contact support.');
}
$ipKey = rl_key(client_ip());
if (rl_blocked($conn, 'google_start_ip', $ipKey, 30, 3600)) {
    google_back('Too many attempts. Please wait a while and try again.');
}
rl_hit($conn, 'google_start_ip', $ipKey);

$intent = (($_GET['intent'] ?? '') === 'signup') ? 'signup' : 'login';
header('Cache-Control: no-store');
header('Location: ' . google_start_url($intent, $appId));
exit();
