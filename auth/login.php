<?php

require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/security/lib.php';
require_once __DIR__ . '/../modules/oauth/lib.php';

// Used to spend the same time on unknown emails as on real ones (stops user enumeration by timing).
const LOGIN_DUMMY_HASH = '$2y$10$upDqZzLSCX4uV4KiKU.A2udyaMdt1.F45WteM6RgWu.XNBqNEFfiu';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['user_id']) && empty($_GET['stay'])) {
    header('Location: ' . $base_url . '/dashboard.php');
    exit();
}

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

        if (security_ip_blocked($conn)) {
            $error = "Signing in is not available from this connection right now. Please contact support.";
        } elseif (
            rl_blocked($conn, 'login_email_ip', $keyEmailIp, 5, 900)
            || rl_blocked($conn, 'login_email', $keyEmail, 20, 900)
            || rl_blocked($conn, 'login_ip', $keyIp, 100, 900)
        ) {
            security_event($conn, 'login_locked', $email);
            $error = "Too many failed attempts. Please wait 15 minutes and try again.";
        } else {
            $stmt = $conn->prepare("SELECT id, name, password, blocked FROM users WHERE email=?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $passwordOk = password_verify($password, $user ? $user["password"] : LOGIN_DUMMY_HASH);

            if ($user && $passwordOk && (int) $user['blocked'] === 1) {
                // Only someone who knows the password learns that the account is blocked.
                $error = "This account has been blocked. Please contact support.";
            } elseif ($user && $passwordOk) {

                rl_clear($conn, 'login_email_ip', $keyEmailIp);
                rl_clear($conn, 'login_email', $keyEmail);

                if (password_needs_rehash($user["password"], PASSWORD_DEFAULT)) {
                    $conn->prepare("UPDATE users SET password=? WHERE id=?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user["id"]]);
                }

                session_after_login((int) $user["id"], (string) $user["name"]);

                logAction($conn, $user["id"], 'login', "User logged in");

                // Someone who opened a group invite link before signing in goes back to it.
                $pending = $_SESSION['pending_join'] ?? '';
                unset($_SESSION['pending_join']);
                if (is_string($pending) && preg_match('/^[0-9a-f]{32}$/', $pending)) {
                    header("Location: " . $base_url . "/shared/join.php?code=" . $pending);
                } else {
                    header("Location: " . $base_url . "/dashboard.php");
                }
                exit();

            } else {
                // Same message for "no such email" and "wrong password".
                rl_hit($conn, 'login_email_ip', $keyEmailIp);
                rl_hit($conn, 'login_email', $keyEmail);
                rl_hit($conn, 'login_ip', $keyIp);
                security_event($conn, 'login_fail', $email);
                $error = "Invalid email or password.";
            }
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php ef_auth_start(['title' => 'Log in']); ?>
<h2>Welcome back</h2>
<p class="s3">Log in to continue to ExpenseFlow.</p>
<?= ef_msg($notice, $noticeType === 'success' ? 'ok' : 'err') ?>
<?= ef_msg($error) ?>
<?= google_button('login') ?>
<form method="POST" autocomplete="on">
    <?= csrf_field() ?>
    <?= ef_field('EMAIL', 'email', 'mail', 'email', $email, ['placeholder' => 'you@example.com', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
    <?= ef_field('PASSWORD', 'password', 'lock', 'password', '', ['placeholder' => 'Your password', 'required' => true, 'autocomplete' => 'current-password']) ?>
    <div class="rw"><span></span><a class="lnk" href="forgot-password.php">Forgot password?</a></div>
    <button class="btn lg" style="width:100%" type="submit">Log in</button>
</form>
<div class="ft2">New to ExpenseFlow? <a class="lnk" href="register.php">Create account</a></div>
<div class="ft2"><a class="lnk" href="<?= e(ef_url('index.php')) ?>"><?= ef_icon('bk') ?> Back to home</a></div>
<?php ef_auth_end(); ?>
