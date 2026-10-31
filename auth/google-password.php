<?php
// Right after the first "Continue with Google" the person can choose a password of their own, or skip it.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/oauth/lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$uid = (int) $_SESSION['user_id'];
header('Cache-Control: no-store');
if (oauth_pw_state($conn, $uid) === 0) { // already has a password of their own
    header('Location: ../dashboard.php');
    exit();
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['skip'])) {
        if (oauth_pw_state($conn, $uid) === 1) {
            oauth_set_pw_state($conn, $uid, 2);
        }
        header('Location: ../dashboard.php');
        exit();
    } else {
        $new = (string) ($_POST['password'] ?? '');
        $key = rl_key((string) $uid);
        if (rl_blocked($conn, 'google_set_pw', $key, 10, 900)) {
            $error = 'Too many attempts. Please try again in a few minutes.';
        } elseif ($new !== (string) ($_POST['confirm_password'] ?? '')) {
            $error = 'The two passwords do not match.';
        } elseif (($policy = password_policy_error($new)) !== null) {
            rl_hit($conn, 'google_set_pw', $key);
            $error = $policy;
        } else {
            $conn->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            oauth_password_set($conn, $uid);
            logAction($conn, $uid, 'update_profile', 'Set a password (account created with Google)');
            $_SESSION['success'] = 'Password saved. You can now sign in with Google or with your e-mail and password.';
            header('Location: ../dashboard.php');
            exit();
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
ef_auth_start(['title' => 'Set a password']); ?>
<div class="big"><?= ef_icon('lock') ?></div>
<h2 class="c2">Set a password?</h2>
<p class="s3 c2">You signed in with Google, so you do not have a password yet. You can set one now to also sign in with your e-mail, or skip and keep using Google. You can do this later from Profile.</p>
<?= ef_msg($error) ?>
<form method="POST" autocomplete="off">
    <?= csrf_field() ?>
    <?= ef_field('NEW PASSWORD', 'password', 'lock', 'password', '', ['placeholder' => 'At least 8 characters', 'autocomplete' => 'new-password', 'required' => true]) ?>
    <?= ef_field('CONFIRM PASSWORD', 'confirm_password', 'lock', 'password', '', ['placeholder' => 'Type it again', 'autocomplete' => 'new-password', 'required' => true]) ?>
    <p class="s3" style="font-size:.76rem">Use upper and lower case letters, a number and a symbol.</p>
    <button class="btn lg" style="width:100%" type="submit">Save password</button>
    <div style="height:10px"></div>
    <button class="btn o lg" style="width:100%" type="submit" name="skip" value="1" formnovalidate>Skip for now</button>
</form>
<?php ef_auth_end();
