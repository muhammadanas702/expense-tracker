<?php
// Step 2 of "Continue with Google": Google sends the person back here with a one-time code.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/oauth/lib.php';
require_once __DIR__ . '/../modules/security/lib.php';
require_once __DIR__ . '/../modules/settings/lib.php';

/** Sends the person back to the login page with a friendly message and a short reason code (also written to the PHP log). */
function google_fail(string $msg, string $code = ''): void
{
    global $base_url;
    if ($code !== '') {
        error_log('ExpenseFlow Google sign-in refused: ' . $code);
        $msg .= ' (reason: ' . preg_replace('/[^A-Za-z0-9 :_\-\.]/', '', substr($code, 0, 90)) . ')';
    }
    $_SESSION['reset_message'] = $msg;
    $_SESSION['reset_type'] = 'error';
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}

/** Short, safe description of an unexpected error (class, SQL state and where), shown as the reason code. */
function google_exception_code(Throwable $e): string
{
    $state = $e instanceof PDOException && preg_match('/^[0-9A-Z]{5}$/', (string) $e->getCode()) ? ' ' . $e->getCode() : '';
    return 'error ' . (new ReflectionClass($e))->getShortName() . $state . ' ' . basename($e->getFile()) . ':' . $e->getLine();
}

// A real PHP fatal error (not catchable) would otherwise be a blank "HTTP 500": turn it into a normal message instead.
define('EF_CUSTOM_FATAL', true);
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        global $base_url;
        error_log('ExpenseFlow Google sign-in fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        $_SESSION['reset_message'] = 'Google sign-in hit a server problem. Please use your email and password for now. (reason: fatal ' . basename($err['file']) . ':' . $err['line'] . ')';
        $_SESSION['reset_type'] = 'error';
        http_response_code(302);
        header('Location: ' . $base_url . '/auth/login.php');
    }
});

try {
header('Cache-Control: no-store');
$appFlow = !empty($_SESSION['g_oauth']['app']); // started from the Android / Windows app: finish in the browser, then hand over
if (!$appFlow && !empty($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/dashboard.php');
    exit();
}
if (google_settings() === null) {
    google_fail('Google sign-in is not available right now. Please use your email and password.');
}
if (security_ip_blocked($conn)) {
    google_fail('Signing in is not available from this connection right now. Please contact support.');
}

$saved = google_take_state((string) ($_GET['state'] ?? ''), $stateWhy);
if ($saved === null) {
    if ($stateWhy === 'no_state' && !empty($_GET['error'])) {
        google_fail('Google sign-in was cancelled. You can try again or use your email and password.');
    }
    google_fail('The Google sign-in could not be continued. Please press "Continue with Google" again.', 'state ' . $stateWhy);
}
if (!empty($_GET['error']) || empty($_GET['code']) || !is_string($_GET['code'])) {
    google_fail('Google sign-in was cancelled. You can try again or use your email and password.');
}

    $ipKey = rl_key(client_ip());
    if (rl_blocked($conn, 'google_cb_ip', $ipKey, 60, 3600)) {
        google_fail('Too many attempts. Please wait a while and try again.');
    }
    rl_hit($conn, 'google_cb_ip', $ipKey);

    $claims = google_exchange_code((string) $_GET['code'], (string) $saved['verifier'], $exWhy);
    $why = $claims === null ? 'token exchange ' . ($exWhy ?? 'failed') : google_claims_error($claims, google_settings()['client_id']);
    if ($why !== null) {
        google_fail('We could not confirm your Google account. Please try again or use your email and password.', $why);
    }

    [$user, $err] = google_user_for_claims($conn, $claims, settings_signups_open($conn));
    if ($user === null) {
        $msgs = [
            'blocked' => 'This account has been blocked. Please contact support.',
            'closed' => 'New sign-ups are closed for now. Please try again later.',
        ];
        google_fail($msgs[$err] ?? 'Could not sign you in with Google. Please try again.', isset($msgs[$err]) ? '' : (string) ($GLOBALS['google_fail_detail'] ?? 'account ' . $err));
    }

    if ($appFlow) {
        // Do not sign this browser in: ask the person to confirm the app's code, then the app collects the sign-in.
        if (!empty($user['created'])) {
            logAction($conn, (int) $user['id'], 'terms_accepted', 'Continued with Google and accepted the Terms of Use and Privacy Policy on ' . date('Y-m-d H:i'));
            logAction($conn, (int) $user['id'], 'signup_google', 'Account created with Google (app)');
        }
        $_SESSION['g_app_confirm'] = ['id' => (string) $saved['app'], 'user' => (int) $user['id'], 'name' => (string) $user['name'], 't' => time()];
        header('Location: ' . $base_url . '/auth/google-app-confirm.php');
        exit();
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
        header('Location: ' . $base_url . '/' . google_landing_path($conn, (int) $user['id']));
    }
    exit();
} catch (Throwable $e) {
    error_log('ExpenseFlow Google sign-in exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    google_fail('Google sign-in hit a server problem. Please try again, or use your email and password.', google_exception_code($e));
}
