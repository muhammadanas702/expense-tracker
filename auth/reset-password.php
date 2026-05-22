<?php
session_start();
require_once "../config/db.php";

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("Invalid reset link.");
}

// Verify token – using 'token_expiry' column
$stmt = $conn->prepare("SELECT id, name FROM users WHERE reset_token = ? AND token_expiry > NOW()");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("The reset link is invalid or has expired. Please request a new one.");
}

// Process new password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($new_password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        // Update password and clear token columns
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE id = ?");
        $update->execute([$hashed, $user['id']]);

        $_SESSION['reset_message'] = "Password reset successfully! Please login with your new password.";
        $_SESSION['reset_type'] = "success";
        header("Location: login.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/expense-tracker/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 380px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1rem; color: #0f172a; }
        input { width: 100%; padding: 0.7rem; margin: 0.8rem 0; border: 1px solid #cbd5e1; border-radius: 40px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h2>✏️ Set new password</h2>
    <p>Hello <?= htmlspecialchars($user['name']) ?>, enter your new password below.</p>

    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="password" name="password" placeholder="New password (min 6 characters)" required>
        <input type="password" name="confirm_password" placeholder="Confirm new password" required>
        <button type="submit">Reset Password</button>
    </form>
</div>
</body>
</html>