<?php
session_start();

if (!isset($_SESSION['reset_otp']) || !isset($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered = trim($_POST['otp']);
    if ($entered == $_SESSION['reset_otp']) {
        if (time() > $_SESSION['reset_otp_expiry']) {
            $error = "OTP expired. Please request a new one.";
            unset($_SESSION['reset_otp'], $_SESSION['reset_email'], $_SESSION['reset_otp_expiry']);
        } else {
            // OTP correct – go to reset form
            header("Location: set-new-password.php");
            exit();
        }
    } else {
        $error = "Invalid OTP. Try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify OTP</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; text-align: center; }
        input { width: 100%; padding: 0.7rem; margin: 1rem 0; border: 1px solid #cbd5e1; border-radius: 40px; text-align: center; font-size: 1.2rem; letter-spacing: 4px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔐 Verify OTP</h2>
    <p>Enter the 6-digit code sent to <?= htmlspecialchars($_SESSION['reset_email']) ?></p>
    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="text" name="otp" placeholder="000000" maxlength="6" required autofocus>
        <button type="submit">Verify OTP</button>
    </form>
    <p style="margin-top: 1rem;"><a href="forgot-password.php" style="color:#0f766e;">← Request new OTP</a></p>
</div>
</body>
</html>