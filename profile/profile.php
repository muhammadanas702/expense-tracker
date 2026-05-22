<?php
session_start();
require_once "config/db.php";
require_once "includes/logging.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$error = "";
$success = "";

// Fetch current user data
$stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['update_profile'])) {
        $new_name = trim($_POST['name']);
        $new_email = trim($_POST['email']);

        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$new_email, $user_id]);
        if ($check->fetch()) {
            $error = "Email is already used by another account.";
        } else {
            $update = $conn->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $update->execute([$new_name, $new_email, $user_id]);
            $_SESSION["user_name"] = $new_name;
            $success = "Profile updated successfully.";
            $user['name'] = $new_name;
            $user['email'] = $new_email;
            logAction($user_id, 'update_profile', "Updated name/email");
        }
    }
    elseif (isset($_POST['update_password'])) {
        $current = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $pass_stmt->execute([$user_id]);
        $hashed = $pass_stmt->fetchColumn();

        if (!password_verify($current, $hashed)) {
            $error = "Current password is incorrect.";
        } elseif ($new_pass !== $confirm) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_pass) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $new_hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$new_hashed, $user_id]);
            $success = "Password changed successfully.";
            logAction($user_id, 'update_profile', "Changed password");
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/expense-tracker/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>My Profile - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .profile-container { background: white; border-radius: 32px; padding: 2rem; width: 500px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; font-weight: 500; color: #334155; margin-bottom: 0.3rem; }
        input { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; text-align: center; }
        .success { background: #d1fae5; color: #065f46; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; text-align: center; }
        .separator { height: 1px; background: #e2e8f0; margin: 2rem 0; }
        .back-link { display: inline-block; margin-top: 1rem; color: #0f766e; text-decoration: none; text-align: center; width: 100%; }
        hr { margin: 1.5rem 0; }
    </style>
</head>
<body>
<div class="profile-container">
    <h2>👤 My Profile</h2>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
        <button type="submit" name="update_profile">Update Profile</button>
    </form>

    <hr class="separator">

    <form method="POST">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required>
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required>
        </div>
        <button type="submit" name="update_password">Change Password</button>
    </form>

    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>