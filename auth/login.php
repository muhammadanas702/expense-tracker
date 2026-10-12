<?php

require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';

// Used to spend the same time on unknown emails as on real ones (stops user enumeration by timing).
const LOGIN_DUMMY_HASH = '$2y$10$upDqZzLSCX4uV4KiKU.A2udyaMdt1.F45WteM6RgWu.XNBqNEFfiu';

$error = "";
$email = "";

// One-time message left by another page (for example "Password reset successfully").
$notice = $_SESSION['reset_message'] ?? '';
$noticeType = (($_SESSION['reset_type'] ?? '') === 'success') ? 'success' : 'error';
unset($_SESSION['reset_message'], $_SESSION['reset_type']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim((string) ($_POST["email"] ?? ''));
    $password = (string) ($_POST["password"] ?? '');

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } else {
        $ip = client_ip();
        $keyEmailIp = rl_key($email, $ip);
        $keyEmail = rl_key($email);
        $keyIp = rl_key($ip);

        if (
            rl_blocked($conn, 'login_email_ip', $keyEmailIp, 5, 900)
            || rl_blocked($conn, 'login_email', $keyEmail, 20, 900)
            || rl_blocked($conn, 'login_ip', $keyIp, 100, 900)
        ) {
            $error = "Too many failed attempts. Please wait 15 minutes and try again.";
        } else {
            $stmt = $conn->prepare("SELECT id, name, password FROM users WHERE email=?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $passwordOk = password_verify($password, $user ? $user["password"] : LOGIN_DUMMY_HASH);

            if ($user && $passwordOk) {

                rl_clear($conn, 'login_email_ip', $keyEmailIp);
                rl_clear($conn, 'login_email', $keyEmail);

                if (password_needs_rehash($user["password"], PASSWORD_DEFAULT)) {
                    $conn->prepare("UPDATE users SET password=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user["id"]]);
                }

                session_after_login((int) $user["id"], (string) $user["name"]);

                logAction($conn, $user["id"], 'login', "User logged in");

                header("Location: " . $base_url . "/dashboard.php");
                exit();

            } else {
                // Same message for "no such email" and "wrong password".
                rl_hit($conn, 'login_email_ip', $keyEmailIp);
                rl_hit($conn, 'login_email', $keyEmail);
                rl_hit($conn, 'login_ip', $keyIp);
                $error = "Invalid email or password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="../assets/auth.css">


</head>
<body>
<div class="auth-wrapper">
    <div class="card">
        <div class="logo">Expense<span>Flow</span></div>
        <h2>Welcome Back</h2>
        <p class="subtitle">Login to continue managing your finances</p>
        <?php if ($notice !== '') { ?>
            <div class="<?= e($noticeType) ?>"><?= e($notice) ?></div>
        <?php } ?>
        <?php if(!empty($error)) { ?>
            <div class="error"><?= e($error) ?></div>
        <?php } ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="email" name="email" placeholder="Enter Email" value="<?= e($email) ?>" required>
            <input type="password" name="password" placeholder="Enter Password" required>
            <div style="text-align: right; margin-top: -0.5rem; margin-bottom: 1rem;">
                <a href="forgot-password.php" style="font-size: 0.85rem; color: #0f766e; text-decoration: none;">Forgot password?</a>
            </div>
            <button type="submit">Login</button>
        </form>
        <div class="links">
            Don't have an account? <a href="register.php">Register</a>
        </div>
    </div>
</div>
</body>
</html>
