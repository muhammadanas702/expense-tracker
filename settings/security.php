<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();
$gpw = oauth_pw_state($conn, $user_id) !== 0; // signed up with Google and no password of their own yet

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    $success = '';
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
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
    settings_done($success, $error);
}
$ids = [];
try {
    $st = $conn->prepare('SELECT provider FROM user_identities WHERE user_id = ?');
    $st->execute([$user_id]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
}
settings_page_start('security', $user);
?>
    <div class="g card">
      <div class="t"><?= $gpw ? 'Password' : 'Change password' ?></div>
<?php if ($gpw): ?>
      <p class="k" style="margin:-4px 0 12px">You signed up with Google, so you have no password yet. You can keep using Google, or set a password to also sign in with your e-mail.</p>
      <a class="btn" href="<?= e($base_url) ?>/auth/google-password.php">Set a password</a>
<?php else: ?>
      <p class="k" style="margin:-4px 0 12px">Choose a new password. You need your current one.</p>
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fl"><label for="current_password">Current password</label><input class="inp" id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="fl"><label for="new_password">New password</label><input class="inp" id="new_password" type="password" name="new_password" autocomplete="new-password" required><small class="k">At least 8 characters with letters and numbers.</small></div>
        <div class="fl"><label for="confirm_password">Confirm new password</label><input class="inp" id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required></div>
        <button class="btn" type="submit" name="update_password" value="1">Change password</button>
      </form>
<?php endif; ?>
    </div>
    <div class="g card">
      <div class="t">How you sign in</div>
      <div class="row"><span><?= ef_icon('mail') ?> E-mail and password<small><?= $gpw ? 'Not set yet' : 'Set' ?></small></span><b><?= $gpw ? '' : ef_icon('ok', '#16a34a') ?></b></div>
      <div class="row"><span><?= ef_icon('globe') ?> Google<small><?= in_array('google', $ids, true) ? 'Connected' : 'Not used. Sign in with Google from the login page using this e-mail to connect it.' ?></small></span><b><?= in_array('google', $ids, true) ? ef_icon('ok', '#16a34a') : '' ?></b></div>
    </div>
<?php settings_page_end();
