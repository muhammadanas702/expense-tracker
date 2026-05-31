<?php
session_start();
require_once "../config/app.php";
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="<?= $base_url ?>/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1rem; color: #0f172a; }
        input { width: 100%; padding: 0.7rem; margin: 0.8rem 0; border: 1px solid #cbd5e1; border-radius: 40px; font-family: inherit; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .message { margin-top: 1rem; padding: 0.5rem; border-radius: 20px; text-align: center; }
        .error { background: #fee2e2; color: #b91c1c; }
        .success { background: #d1fae5; color: #065f46; }
        a { display: block; text-align: center; margin-top: 1rem; color: #0f766e; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔐 Reset your password</h2>
    <p style="color: #475569;">Enter your email address and we'll send you a <strong>6‑digit OTP</strong> to reset your password.</p>

    <?php if(isset($_SESSION['reset_message'])): ?>
        <div class="message <?= $_SESSION['reset_type'] ?? 'error' ?>">
            <?= htmlspecialchars($_SESSION['reset_message']) ?>
        </div>
        <?php unset($_SESSION['reset_message'], $_SESSION['reset_type']); ?>
    <?php endif; ?>

    <!-- Changed action to send-otp.php -->
    <form action="send-otp.php" method="POST">
        <input type="email" name="email" placeholder="Your registered email" required>
        <button type="submit">Send OTP</button>
    </form>
    <a href="login.php">← Back to Login</a>
</div>
</body>
</html>