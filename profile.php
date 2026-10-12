<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}
$user_id = (int) $_SESSION["user_id"];
$error = "";
$success = "";

$stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif (isset($_POST['update_profile'])) {
        $new_name = clean_text($_POST['name'] ?? '', 100);
        if ($new_name === null) {
            $error = "Please enter a name (up to 100 characters).";
        } else {
            $conn->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$new_name, $user_id]);
            $_SESSION["user_name"] = $new_name;
            $user['name'] = $new_name;
            $success = "Profile updated successfully.";
            logAction($conn, $user_id, 'update_profile', "Updated name");
        }
    } elseif (isset($_POST['update_password'])) {
        $current  = (string) ($_POST['current_password'] ?? '');
        $new_pass = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        $key = rl_key((string) $user_id);
        if (rl_blocked($conn, 'change_password', $key, 5, 900)) {
            $error = "Too many attempts. Please try again in a few minutes.";
        } else {
            $pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $pass_stmt->execute([$user_id]);
            $hashed = (string) $pass_stmt->fetchColumn();

            if (!password_verify($current, $hashed)) {
                rl_hit($conn, 'change_password', $key);
                $error = "Current password is incorrect.";
            } elseif ($new_pass !== $confirm) {
                $error = "New passwords do not match.";
            } elseif (($policy = password_policy_error($new_pass)) !== null) {
                $error = $policy;
            } else {
                $conn->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user_id]);
                rl_clear($conn, 'change_password', $key);
                session_regenerate_id(true);
                $success = "Password changed successfully.";
                logAction($conn, $user_id, 'update_profile', "Changed password");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <?php require_once __DIR__ . '/includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>My Profile - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/responsive.css">
    <style>
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .profile-container { background: white; border-radius: 32px; padding: 2rem; width: 550px; max-width: 100%; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; font-weight: 500; color: #334155; margin-bottom: 0.3rem; }
        input { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; outline: none; }
        input:focus { border-color: #0f766e; }
        input[readonly] { background-color: #f1f5f9; cursor: not-allowed; }
        .password-wrapper { position: relative; display: flex; align-items: center; }
        .password-wrapper input { padding-right: 2.5rem; }
        .password-wrapper i { position: absolute; right: 15px; cursor: pointer; color: #64748b; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
        .error, .success { padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; text-align: center; }
        .error { background: #fee2e2; color: #b91c1c; }
        .success { background: #d1fae5; color: #065f46; }
        .separator { height: 1px; background: #e2e8f0; margin: 2rem 0; }
        .back-link { display: inline-block; margin-top: 1rem; color: #0f766e; text-decoration: none; text-align: center; width: 100%; }
        .requirements { list-style: none; margin-top: 8px; font-size: 0.7rem; color: #64748b; display: flex; flex-wrap: wrap; gap: 12px; }
        .requirements li.valid { color: #10b981; text-decoration: line-through; }
    </style>
</head>
<body>
<div class="profile-container">
    <h2>👤 My Profile</h2>
    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?= e($success) ?></div><?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" value="<?= e($user['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= e($user['email']) ?>" readonly>
        </div>
        <button type="submit" name="update_profile">Update Profile</button>
    </form>
    <hr class="separator">
    <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Current Password</label>
            <div class="password-wrapper"><input type="password" name="current_password" id="current_password" required><i class="fas fa-eye-slash" onclick="togglePassword('current_password', this)"></i></div>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <div class="password-wrapper"><input type="password" name="new_password" id="new_password" required><i class="fas fa-eye-slash" onclick="togglePassword('new_password', this)"></i></div>
            <ul class="requirements" id="passwordRequirements">
                <li id="req-length">✗ 8+ characters</li>
                <li id="req-upper">✗ Uppercase (A-Z)</li>
                <li id="req-lower">✗ Lowercase (a-z)</li>
                <li id="req-number">✗ Number (0-9)</li>
                <li id="req-special">✗ Special char (@#$%^&+=)</li>
            </ul>
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <div class="password-wrapper"><input type="password" name="confirm_password" id="confirm_password" required><i class="fas fa-eye-slash" onclick="togglePassword('confirm_password', this)"></i></div>
        </div>
        <button type="submit" name="update_password">Change Password</button>
    </form>
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
<script>
    function togglePassword(fieldId, icon) {
        const field = document.getElementById(fieldId);
        if (field.type === "password") { field.type = "text"; icon.classList.remove("fa-eye-slash"); icon.classList.add("fa-eye"); }
        else { field.type = "password"; icon.classList.remove("fa-eye"); icon.classList.add("fa-eye-slash"); }
    }
    const newPass = document.getElementById('new_password');
    function checkStrength(){
        const val = newPass.value;
        document.getElementById('req-length').innerHTML = val.length>=8 ? '✓ 8+ characters' : '✗ 8+ characters'; document.getElementById('req-length').classList.toggle('valid', val.length>=8);
        document.getElementById('req-upper').innerHTML = /[A-Z]/.test(val) ? '✓ Uppercase (A-Z)' : '✗ Uppercase (A-Z)'; document.getElementById('req-upper').classList.toggle('valid', /[A-Z]/.test(val));
        document.getElementById('req-lower').innerHTML = /[a-z]/.test(val) ? '✓ Lowercase (a-z)' : '✗ Lowercase (a-z)'; document.getElementById('req-lower').classList.toggle('valid', /[a-z]/.test(val));
        document.getElementById('req-number').innerHTML = /[0-9]/.test(val) ? '✓ Number (0-9)' : '✗ Number (0-9)'; document.getElementById('req-number').classList.toggle('valid', /[0-9]/.test(val));
        document.getElementById('req-special').innerHTML = /[\W_]/.test(val) ? '✓ Special char' : '✗ Special char'; document.getElementById('req-special').classList.toggle('valid', /[\W_]/.test(val));
    }
    newPass.addEventListener('input', checkStrength);
</script>
</body>
</html>