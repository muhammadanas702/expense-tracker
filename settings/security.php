<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();
if (is_file(__DIR__ . '/../modules/twofactor/lib.php')) {
    require_once __DIR__ . '/../modules/twofactor/lib.php';
}
$tf = function_exists('twofa_available'); // module present
$tfStaff = $tf && twofa_mandatory($conn, $user_id);
$tfOn = $tf && twofa_enabled($conn, $user_id);
$tfPending = (string) ($_SESSION['twofa_ui'] ?? ''); // 'on' or 'off' while a code is awaited
$gpw = oauth_pw_state($conn, $user_id) !== 0; // signed up with Google and no password of their own yet

if ($tf && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['twofa_action'])) {
    $act = (string) $_POST['twofa_action'];
    if (!csrf_valid()) {
        settings_done('', 'Your session expired. Please try again.');
    }
    if (!twofa_available()) {
        settings_done('', 'Two-step sign-in is not available on this server right now.');
    }
    if ($act === 'start_on' || $act === 'start_off') {
        $purpose = $act === 'start_on' ? 'on' : 'off';
        if ($purpose === 'off' && $tfStaff) {
            settings_done('', 'Two-step sign-in is required for your account.');
        }
        if (($purpose === 'on') === $tfOn) {
            settings_done('', $tfOn ? 'Two-step sign-in is already on.' : 'Two-step sign-in is already off.');
        }
        if (twofa_send_code($conn, $user_id, $purpose)) {
            $_SESSION['twofa_ui'] = $purpose;
            settings_done('We sent a 6-digit code to your e-mail. Enter it below.');
        }
        settings_done('', 'We could not send the code right now. Please try again in a few minutes.');
    }
    if ($act === 'confirm' && in_array($tfPending, ['on', 'off'], true)) {
        $r = twofa_check_code($user_id, $tfPending, (string) ($_POST['code'] ?? ''));
        if ($r === 'ok') {
            twofa_set($conn, $user_id, $tfPending === 'on');
            logAction($conn, $user_id, 'update_profile', $tfPending === 'on' ? 'Turned on two-step sign-in' : 'Turned off two-step sign-in');
            unset($_SESSION['twofa_ui']);
            settings_done($tfPending === 'on' ? 'Two-step sign-in is on. You will enter an e-mailed code each time you log in.' : 'Two-step sign-in is off.');
        }
        settings_done('', $r === 'bad' ? 'Invalid code. Try again.' : 'That code has expired. Ask for a new one.');
    }
    if ($act === 'cancel') {
        unset($_SESSION['twofa_ui'], $_SESSION['twofa_on'], $_SESSION['twofa_off']);
    }
    settings_done();
}
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
<?php if ($tf): ?>
    <div class="g card" id="twofa">
      <div class="t">Two-step sign-in</div>
<?php if (!twofa_available()): ?>
      <p class="k" style="margin:-4px 0 0">Not available right now: the server cannot send e-mail.</p>
<?php elseif ($tfStaff): ?>
      <p class="k" style="margin:-4px 0 0"><?= ef_icon('ok', '#16a34a') ?> Required for your account (set by the site admin). After your password you enter a 6-digit code we e-mail you.</p>
<?php elseif (in_array($tfPending, ['on', 'off'], true)): ?>
      <p class="k" style="margin:-4px 0 12px">Enter the 6-digit code we sent to your e-mail to turn two-step sign-in <b><?= e($tfPending) ?></b>.</p>
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="twofa_action" value="confirm">
        <div class="fl"><label for="twofa_code">Code</label><input class="inp otp" id="twofa_code" type="text" name="code" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required></div>
        <button class="btn" type="submit">Confirm</button>
      </form>
      <form method="POST" style="margin-top:8px"><?= csrf_field() ?><button class="btn o" type="submit" name="twofa_action" value="cancel" formnovalidate>Cancel</button></form>
<?php elseif ($tfOn): ?>
      <p class="k" style="margin:-4px 0 12px"><?= ef_icon('ok', '#16a34a') ?> On. After your password you enter a 6-digit code we e-mail you.</p>
      <form method="POST"><?= csrf_field() ?><button class="btn o" type="submit" name="twofa_action" value="start_off">Turn off</button></form>
<?php else: ?>
      <p class="k" style="margin:-4px 0 12px">Add a second step: after your password you enter a 6-digit code we e-mail you. Even if someone learns your password they cannot get in.</p>
      <form method="POST"><?= csrf_field() ?><button class="btn" type="submit" name="twofa_action" value="start_on">Turn on</button></form>
<?php endif; ?>
    </div>
<?php endif; ?>
    <div class="g card">
      <div class="t">How you sign in</div>
      <div class="row"><span><?= ef_icon('mail') ?> E-mail and password<small><?= $gpw ? 'Not set yet' : 'Set' ?></small></span><b><?= $gpw ? '' : ef_icon('ok', '#16a34a') ?></b></div>
      <div class="row"><span><?= ef_icon('globe') ?> Google<small><?= in_array('google', $ids, true) ? 'Connected' : 'Not used. Sign in with Google from the login page using this e-mail to connect it.' ?></small></span><b><?= in_array('google', $ids, true) ? ef_icon('ok', '#16a34a') : '' ?></b></div>
    </div>
<?php settings_page_end();
