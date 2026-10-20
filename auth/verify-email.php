<?php
/** Step 2 of sign-up: the person types the 6-digit code we emailed. Only then is the account created. */
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../modules/signup/lib.php';

$pending = signup_pending();
if (!$pending) {
    header('Location: register.php');
    exit();
}

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? 'verify');
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($do === 'cancel') {
        signup_pending_clear();
        header('Location: register.php');
        exit();
    } elseif ($do === 'resend') {
        $ipKey = rl_key(client_ip());
        if (signup_resend_wait($pending) > 0) {
            $error = 'Please wait ' . signup_resend_wait($pending) . ' seconds before asking for another code.';
        } elseif ((int) $pending['sends'] >= SIGNUP_MAX_SENDS) {
            $error = 'Too many codes were sent. Please start again with the correct address.';
        } elseif (rl_blocked($conn, 'signup_mail_email', rl_key($pending['email']), 5, 3600) || rl_blocked($conn, 'signup_mail_ip', $ipKey, 15, 3600)) {
            $error = 'Too many confirmation emails were requested. Please try again in an hour.';
        } else {
            $code = signup_code_new();
            rl_hit($conn, 'signup_mail_email', rl_key($pending['email']));
            rl_hit($conn, 'signup_mail_ip', $ipKey);
            if (sendVerifyMail($pending['email'], $pending['name'], $code)) {
                $_SESSION['signup_pending']['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
                $_SESSION['signup_pending']['expires'] = time() + SIGNUP_CODE_TTL;
                $_SESSION['signup_pending']['attempts'] = 0;
                $_SESSION['signup_pending']['sent_at'] = time();
                $_SESSION['signup_pending']['sends'] = (int) $pending['sends'] + 1;
                $pending = signup_pending();
                $notice = 'A new code is on its way.';
            } else {
                $error = 'We could not send the email. Please try again in a moment.';
            }
        }
    } else {
        [$ok, $err] = signup_check_code((string) ($_POST['code'] ?? ''));
        if (!$ok) {
            $error = (string) $err;
            $pending = signup_pending() ?? $pending;
        } else {
            $dup = $conn->prepare('SELECT 1 FROM users WHERE email = ?');
            $dup->execute([$pending['email']]);
            if ($dup->fetchColumn()) {
                signup_pending_clear();
                $_SESSION['reset_message'] = 'This email already has an account. Please log in.';
                $_SESSION['reset_type'] = 'error';
                header('Location: login.php');
                exit();
            }
            try {
                $conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute([$pending['name'], $pending['email'], $pending['hash']]);
                signup_pending_clear();
                $_SESSION['reset_message'] = 'Email confirmed and account created. Please log in.';
                $_SESSION['reset_type'] = 'success';
                header('Location: login.php');
                exit();
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    signup_pending_clear();
                    $_SESSION['reset_message'] = 'This email already has an account. Please log in.';
                    $_SESSION['reset_type'] = 'error';
                    header('Location: login.php');
                    exit();
                }
                error_log('ExpenseFlow registration failed: ' . $e->getMessage());
                $error = 'Could not create the account. Please try again.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
ef_auth_start(['title' => 'Confirm your email']); ?>
<div class="big"><?= ef_icon('mail') ?></div>
<h2 class="c2">Confirm your email</h2>
<p class="s3 c2">We sent a 6-digit code to <b><?= e(signup_mask($pending['email'])) ?></b>. Your account is created after you enter it.</p>
<?= ef_msg($notice, 'ok') ?>
<?= ef_msg($error) ?>
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="verify">
    <input class="inp otp" type="text" name="code" placeholder="000000" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus aria-label="6-digit code">
    <div style="height:16px"></div>
    <button class="btn lg" style="width:100%" type="submit">Confirm and create account</button>
</form>
<form method="POST" class="ft2" style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
    <?= csrf_field() ?>
    <button class="lnk" type="submit" name="do" value="resend" style="background:none;border:0;cursor:pointer;font:inherit">Send a new code</button>
    <button class="lnk" type="submit" name="do" value="cancel" style="background:none;border:0;cursor:pointer;font:inherit">Wrong address? Start again</button>
</form>
<div class="ft2">Nothing arrived? Check your spam folder.</div>
<?php ef_auth_end(); ?>
