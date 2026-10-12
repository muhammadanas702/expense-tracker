<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';

function otp_redirect_back(string $message, string $type = 'error'): void
{
    $_SESSION['reset_message'] = $message;
    $_SESSION['reset_type'] = $type;
    header("Location: forgot-password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: forgot-password.php");
    exit();
}

if (!csrf_valid()) {
    otp_redirect_back("Your session expired. Please try again.");
}

$email = trim((string) ($_POST['email'] ?? ''));

if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    otp_redirect_back("Please enter a valid email address.");
}

// Limit how many codes can be requested (stops email flooding and code guessing).
$emailKey = rl_key($email);
$ipKey = rl_key(client_ip());

if (
    rl_blocked($conn, 'otp_send_email', $emailKey, 5, 3600)
    || rl_blocked($conn, 'otp_send_ip', $ipKey, 20, 3600)
) {
    otp_redirect_back("Too many reset requests. Please wait a while and try again.");
}
rl_hit($conn, 'otp_send_email', $emailKey);
rl_hit($conn, 'otp_send_ip', $ipKey);

$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Start a fresh reset attempt: nothing from an earlier attempt may carry over.
unset(
    $_SESSION['otp_verified'],
    $_SESSION['otp_verified_at'],
    $_SESSION['reset_otp_hash'],
    $_SESSION['reset_otp_expiry']
);
$_SESSION['reset_email'] = $email;
$_SESSION['reset_otp_attempts'] = 0;

$otp = null;
if ($user) {
    $otp = (string) random_int(100000, 999999);
    $_SESSION['reset_otp_hash'] = password_hash($otp, PASSWORD_DEFAULT);
    $_SESSION['reset_otp_expiry'] = time() + 600; // 10 minutes
} else {
    password_hash('000000', PASSWORD_DEFAULT); // same work as for a real account
}

// The answer is identical whether or not the email is registered.
$_SESSION['reset_message'] = "If an account exists for that email, a 6-digit code has been sent. It is valid for 10 minutes.";
$_SESSION['reset_type'] = "success";

// Answer the visitor first, then send the email, so the response time does not reveal whether the account exists.
header("Location: verify-otp.php");
session_write_close();
ignore_user_abort(true);
header('Connection: close');
header('Content-Length: 0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if ($user && $otp !== null) {
    sendOtpMail($email, (string) $user['name'], $otp);
}
exit();
