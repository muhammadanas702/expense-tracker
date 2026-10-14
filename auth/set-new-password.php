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

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php ef_auth_start(['title' => 'New password']); ?>
<h2>Create new password</h2>
<p class="s3">Hello <?= e($user['name']) ?>, choose a strong password you have not used before.</p>
<?= ef_msg($error) ?>
<form method="POST">
    <?= csrf_field() ?>
    <?= ef_field('NEW PASSWORD', 'password', 'lock', 'password', '', ['placeholder' => 'Min. 8 characters', 'required' => true, 'autocomplete' => 'new-password', 'data-strength' => true]) ?>
    <div class="mt"><i></i><i></i><i></i><i></i></div>
    <div style="margin:14px 0 16px">
        <?= ef_check('At least 8 characters') ?>
        <?= ef_check('Upper and lower case letters') ?>
        <?= ef_check('A number and a symbol') ?>
    </div>
    <?= ef_field('CONFIRM PASSWORD', 'confirm_password', 'lock', 'password', '', ['placeholder' => 'Repeat password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button class="btn lg" style="width:100%" type="submit">Reset password</button>
</form>
<?php ef_auth_end(); ?>
