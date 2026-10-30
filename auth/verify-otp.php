<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

if (empty($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

$notice = $_SESSION['reset_message'] ?? '';
unset($_SESSION['reset_message'], $_SESSION['reset_type']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } else {
        $entered = preg_replace('/\D/', '', (string) ($_POST['otp'] ?? ''));
        $hash = $_SESSION['reset_otp_hash'] ?? null;
        $expiry = (int) ($_SESSION['reset_otp_expiry'] ?? 0);
        $attempts = (int) ($_SESSION['reset_otp_attempts'] ?? 0);

        if ($attempts >= 5) {
            // A code that was guessed at too often is thrown away.
            unset($_SESSION['reset_otp_hash'], $_SESSION['reset_otp_expiry']);
            $error = "Too many wrong attempts. Please request a new code.";
        } elseif (!$hash || time() > $expiry) {
            unset($_SESSION['reset_otp_hash'], $_SESSION['reset_otp_expiry']);
            $error = "This code is invalid or has expired. Please request a new one.";
        } elseif (strlen($entered) === 6 && password_verify($entered, $hash)) {
            // Only now is the visitor allowed to choose a new password.
            $_SESSION['otp_verified'] = true;
            $_SESSION['otp_verified_at'] = time();
            unset($_SESSION['reset_otp_hash'], $_SESSION['reset_otp_expiry'], $_SESSION['reset_otp_attempts']);
            header("Location: set-new-password.php");
            exit();
        } else {
            $_SESSION['reset_otp_attempts'] = $attempts + 1;
            $error = "Invalid code. Try again.";
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php
$otpEmail = (string) $_SESSION['reset_email'];
$at = strpos($otpEmail, '@');
$masked = $at === false ? '' : substr($otpEmail, 0, min(2, $at)) . '•••' . substr($otpEmail, $at);
ef_auth_start(['title' => 'Check your email']); ?>
<div class="big"><?= ef_icon('mail') ?></div>
<h2 class="c2">Check your email</h2>
<p class="s3 c2">We sent a 6-digit code to <b><?= e($masked) ?></b></p>
<?= ef_msg($error === '' ? $notice : '', 'ok') ?>
<?= ef_msg($error) ?>
<form method="POST">
    <?= csrf_field() ?>
    <input class="inp otp" type="text" name="otp" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus aria-label="6-digit code">
    <div style="height:16px"></div>
    <button class="btn lg" style="width:100%" type="submit">Verify code</button>
</form>
<div class="aft">Did not get it? <a class="lnk" href="forgot-password.php">Request a new code</a></div>
<?php ef_auth_end(); ?>
