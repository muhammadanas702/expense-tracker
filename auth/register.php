<?php

require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/security/lib.php';
require_once __DIR__ . '/../modules/settings/lib.php';
require_once __DIR__ . '/../modules/signup/lib.php';
require_once __DIR__ . '/../modules/oauth/lib.php';
require_once __DIR__ . '/../config/mail.php';

$msg = "";

$name = "";
$email = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $name = trim((string) ($_POST["name"] ?? ''));
    $email = trim((string) ($_POST["email"] ?? ''));
    $emailRaw = $email;

    $pass = (string) ($_POST["password"] ?? '');
    $cpass = (string) ($_POST["confirm_password"] ?? '');

    $ipKey = rl_key(client_ip());

    if(!csrf_valid()){

        $msg = "Your session expired. Please try again.";
    }

    elseif(empty($_POST['agree'])){

        $msg = "Please accept the Terms of Use and the Privacy Policy to create an account.";
    }

    elseif(!settings_signups_open($conn)){

        $msg = "New sign-ups are closed for now. Please try again later.";
    }

    elseif(security_ip_blocked($conn)){

        $msg = "Sign-up is not available from this connection right now.";
    }

    elseif(rl_blocked($conn, 'register_ip', $ipKey, 10, 3600)){

        security_event($conn, 'signup_limit');
        $msg = "Too many sign-ups from this connection. Please try again later.";
    }

    /* NAME VALIDATION */

    elseif(clean_text($name, 100) === null){

        $msg = "Please enter your name (up to 100 characters).";
    }

    /* EMAIL VALIDATION: plausible address, not a throw-away service, domain can receive mail */

    elseif(($email = signup_normalize_email($emailRaw)) === ''){

        $email = $emailRaw;
        $msg = "Invalid email format!";
    }

    elseif(($emailProblem = signup_email_problem($email)) !== null){

        $msg = $emailProblem;
    }

    /* PASSWORD VALIDATION */

    elseif(($policyError = password_policy_error($pass)) !== null){

        $msg = $policyError;
    }

    /* PASSWORD MATCH */

    elseif($pass !== $cpass){

        $msg = "Passwords do not match!";
    }

    else{

        rl_hit($conn, 'register_ip', $ipKey);

        $name = clean_text($name, 100);

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email=?
        ");

        $check->execute([$email]);

        if($check->fetch()){

            // Same screen as a new sign-up, so nobody can use this form to find out who has an account.
            // The real owner gets an email that says so; the code typed here can never match.
            if(!rl_blocked($conn, 'signup_mail_email', rl_key($email), 5, 3600)){
                rl_hit($conn, 'signup_mail_email', rl_key($email));
                @sendAccountExistsMail($email, $name);
            }
            signup_pending_set($name, $email, password_hash($pass, PASSWORD_DEFAULT), signup_code_new());
            header("Location: verify-email.php");
            exit();

        }elseif(
            rl_blocked($conn, 'signup_mail_email', rl_key($email), 5, 3600)
            || rl_blocked($conn, 'signup_mail_ip', $ipKey, 15, 3600)
        ){

            $msg = "Too many confirmation emails were requested. Please try again in an hour.";

        }else{

            // The account is only created after the person types the code we email to this address.
            $code = signup_code_new();
            rl_hit($conn, 'signup_mail_email', rl_key($email));
            rl_hit($conn, 'signup_mail_ip', $ipKey);

            if(sendVerifyMail($email, $name, $code)){

                signup_pending_set($name, $email, password_hash($pass, PASSWORD_DEFAULT), $code);
                $_SESSION['signup_pending']['terms_at'] = time();
                header("Location: verify-email.php");
                exit();

            }else{

                $msg = "We could not send the confirmation email. Please check the address and try again in a moment.";
            }
        }
    }
}

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php ef_auth_start(['title' => 'Create account']); ?>
<h2>Create your account</h2>
<p class="s3">Start tracking in under a minute.</p>
<?= ef_msg($msg) ?>
<?= google_button('signup') ?>
<form method="POST">
    <?= csrf_field() ?>
    <?= ef_field('FULL NAME', 'name', 'usr', 'text', $name, ['placeholder' => 'Your name', 'required' => true, 'maxlength' => 100, 'autocomplete' => 'name']) ?>
    <?= ef_field('EMAIL', 'email', 'mail', 'email', $email, ['placeholder' => 'you@example.com', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
    <p class="s3" style="margin:-6px 0 12px;font-size:.78rem">We will email you a 6-digit code to confirm it is yours.</p>
    <?= ef_field('PASSWORD', 'password', 'lock', 'password', '', ['placeholder' => 'Min. 8 characters', 'required' => true, 'autocomplete' => 'new-password', 'data-strength' => true]) ?>
    <div class="mt"><i></i><i></i><i></i><i></i></div>
    <p class="s3" style="margin:8px 0 14px;font-size:.78rem">Use 8+ characters with upper and lower case letters, a number and a symbol.</p>
    <?= ef_field('CONFIRM PASSWORD', 'confirm_password', 'lock', 'password', '', ['placeholder' => 'Repeat password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <label class="agree"><input type="checkbox" name="agree" value="1" required<?= !empty($_POST['agree']) ? ' checked' : '' ?>> <span>I agree to the <a href="../terms.php" target="_blank" rel="noopener">Terms of Use</a> and the <a href="../privacy.php" target="_blank" rel="noopener">Privacy Policy</a>.</span></label>
    <button class="btn lg" style="width:100%" type="submit">Send confirmation code</button>
</form>
<div class="ft2">Already have an account? <a class="lnk" href="login.php">Log in</a></div>
<?php ef_auth_end(); ?>
