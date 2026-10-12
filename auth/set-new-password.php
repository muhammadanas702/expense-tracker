<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';

// The visitor must have entered the correct emailed code (verify-otp.php sets this flag)
// within the last 15 minutes. Having merely requested a code is NOT enough.
$verified = !empty($_SESSION['otp_verified'])
    && !empty($_SESSION['reset_email'])
    && (time() - (int) ($_SESSION['otp_verified_at'] ?? 0)) <= 900;

if (!$verified) {
    unset($_SESSION['otp_verified'], $_SESSION['otp_verified_at']);
    header("Location: forgot-password.php");
    exit();
}

$email = $_SESSION['reset_email'];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset(
        $_SESSION['otp_verified'], $_SESSION['otp_verified_at'], $_SESSION['reset_email'],
        $_SESSION['reset_otp_hash'], $_SESSION['reset_otp_expiry'], $_SESSION['reset_otp_attempts']
    );
    header("Location: forgot-password.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($new_pass !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (($policyError = password_policy_error($new_pass)) !== null) {
        $error = $policyError;
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$hashed, $user['id']]);

        logAction($conn, $user['id'], 'password_reset', "Password reset with an emailed code");

        // Throw away every trace of the reset and start a clean session.
        session_regenerate_id(true);
        unset(
            $_SESSION['otp_verified'], $_SESSION['otp_verified_at'], $_SESSION['reset_email'],
            $_SESSION['reset_otp_hash'], $_SESSION['reset_otp_expiry'], $_SESSION['reset_otp_attempts'],
            $_SESSION['csrf_token']
        );
        $_SESSION['reset_message'] = "Password reset successfully! Please login.";
        $_SESSION['reset_type'] = "success";
        header("Location: login.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; max-width: calc(100% - 32px); border: 1px solid #e2e8f0; }
        input { width: 100%; padding: 0.7rem; margin: 0.8rem 0; border: 1px solid #cbd5e1; border-radius: 40px; box-sizing: border-box; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; }
        .hint { font-size: 0.8rem; color: #64748b; }
    </style>
</head>
<body>
<div class="container">
    <h2>✏️ Set New Password</h2>
    <p>Hello <?= e($user['name']) ?>, create a new password for your account.</p>
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="password" name="password" placeholder="New password" required>
        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
        <p class="hint">At least 8 characters with an uppercase letter, a lowercase letter, a number and a special character.</p>
        <button type="submit">Reset Password</button>
    </form>
</div>
</body>
</html>
