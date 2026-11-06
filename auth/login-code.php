<?php
// Second step of two-step sign-in: the 6-digit code that was e-mailed after the password was accepted.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/security/lib.php';
if (!is_file(__DIR__ . '/../modules/twofactor/lib.php')) {
    header('Location: login.php');
    exit();
}
require_once __DIR__ . '/../modules/twofactor/lib.php';

$pend = $_SESSION['twofa_pending'] ?? null;
// The password step must have happened in this session in the last 15 minutes.
if (!is_array($pend) || time() - (int) ($pend['at'] ?? 0) > 900) {
    unset($_SESSION['twofa_pending'], $_SESSION['twofa_login']);
    header('Location: login.php');
    exit();
}
$uid = (int) $pend['uid'];

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['cancel'])) {
        unset($_SESSION['twofa_pending'], $_SESSION['twofa_login']);
        header('Location: login.php');
        exit();
    } elseif (isset($_POST['resend'])) {
        $sent = (int) ($_SESSION['twofa_last_sent'] ?? 0);
        if (time() - $sent < 30) {
            $error = 'Please wait a few seconds before asking for another code.';
        } elseif (twofa_send_code($conn, $uid, 'login')) {
            $notice = 'A new code is on its way.';
        } else {
            $error = 'We could not send a new code right now. Please wait a while and try again.';
        }
    } else {
        $key = rl_key((string) $uid, client_ip());
        if (rl_blocked($conn, 'twofa_try', $key, 10, 900)) {
            $error = 'Too many wrong codes. Please wait 15 minutes and sign in again.';
        } else {
            $r = twofa_check_code($uid, 'login', (string) ($_POST['code'] ?? ''));
            if ($r === 'ok') {
                rl_clear($conn, 'twofa_try', $key);
                $name = (string) $pend['name'];
                unset($_SESSION['twofa_pending']);
                session_after_login($uid, $name);
                logAction($conn, $uid, 'login', 'User logged in (two-step)');
                $join = $_SESSION['pending_join'] ?? '';
                unset($_SESSION['pending_join']);
                if (is_string($join) && preg_match('/^[0-9a-f]{32}$/', $join)) {
                    header('Location: ' . $base_url . '/shared/join.php?code=' . $join);
                } else {
                    header('Location: ' . $base_url . '/dashboard.php');
                }
                exit();
            }
            rl_hit($conn, 'twofa_try', $key);
            security_event($conn, 'login_fail', 'two-step code');
            $error = $r === 'bad' ? 'Invalid code. Try again.' : 'This code has expired or was used too often. Press "Send a new code".';
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
$st = $conn->prepare('SELECT email FROM users WHERE id = ?');
$st->execute([$uid]);
$mailTo = (string) $st->fetchColumn();
$at = strpos($mailTo, '@');
$masked = $at === false ? '' : substr($mailTo, 0, min(2, $at)) . '•••' . substr($mailTo, $at);
ef_auth_start(['title' => 'Two-step sign-in']); ?>
<div class="big"><?= ef_icon('shield') ?></div>
<h1 class="c2">Enter your code</h1>
<p class="s3 c2">We sent a 6-digit code to <b><?= e($masked) ?></b> to finish signing in.</p>
<?= ef_msg($notice, 'ok') ?>
<?= ef_msg($error) ?>
<form method="POST">
    <?= csrf_field() ?>
    <input class="inp otp" type="text" name="code" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus aria-label="6-digit code">
    <div style="height:16px"></div>
    <button class="btn lg" style="width:100%" type="submit">Sign in</button>
</form>
<form method="POST" class="aft">
    <?= csrf_field() ?>
    Did not get it? <button class="lnk" type="submit" name="resend" value="1" style="background:none;border:0;cursor:pointer;font:inherit">Send a new code</button>
    &nbsp;·&nbsp; <button class="lnk" type="submit" name="cancel" value="1" formnovalidate style="background:none;border:0;cursor:pointer;font:inherit">Cancel</button>
</form>
<?php ef_auth_end(); ?>
