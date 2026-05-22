<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['reset_otp']) || !isset($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

// OTP already verified (came from verify-otp.php)
$email = $_SESSION['reset_email'];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: forgot-password.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($new_pass !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_pass) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$hashed, $user['id']]);

        // Clear OTP session data
        unset($_SESSION['reset_otp'], $_SESSION['reset_email'], $_SESSION['reset_otp_expiry']);
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
    <meta charset="UTF-8">
    <title>Set New Password</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; border: 1px solid #e2e8f0; }
        input { width: 100%; padding: 0.7rem; margin: 0.8rem 0; border: 1px solid #cbd5e1; border-radius: 40px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <h2>✏️ Set New Password</h2>
    <p>Hello <?= htmlspecialchars($user['name']) ?>, create a new password for your account.</p>
    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="password" name="password" placeholder="New password (min 6 chars)" required>
        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
        <button type="submit">Reset Password</button>
    </form>
</div>
</body>
</html>