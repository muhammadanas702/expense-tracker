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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; max-width: calc(100% - 32px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; text-align: center; }
        input { width: 100%; padding: 0.7rem; margin: 1rem 0; border: 1px solid #cbd5e1; border-radius: 40px; text-align: center; font-size: 1.2rem; letter-spacing: 4px; box-sizing: border-box; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; }
        .success { background: #d1fae5; color: #065f46; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔐 Verify OTP</h2>
    <p>Enter the 6-digit code sent to <?= e($_SESSION['reset_email']) ?></p>
    <?php if ($notice !== '' && !$error): ?>
        <div class="success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="text" name="otp" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        <button type="submit">Verify OTP</button>
    </form>
    <p style="margin-top: 1rem;"><a href="forgot-password.php" style="color:#0f766e;">← Request new OTP</a></p>
</div>
</body>
</html>
