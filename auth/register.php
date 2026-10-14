<?php

require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';

$msg = "";

$name = "";
$email = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $name = trim((string) ($_POST["name"] ?? ''));
    $email = trim((string) ($_POST["email"] ?? ''));

    $pass = (string) ($_POST["password"] ?? '');
    $cpass = (string) ($_POST["confirm_password"] ?? '');

    $ipKey = rl_key(client_ip());

    if(!csrf_valid()){

        $msg = "Your session expired. Please try again.";
    }

    elseif(rl_blocked($conn, 'register_ip', $ipKey, 10, 3600)){

        $msg = "Too many sign-ups from this connection. Please try again later.";
    }

    /* NAME VALIDATION */

    elseif(clean_text($name, 100) === null){

        $msg = "Please enter your name (up to 100 characters).";
    }

    /* EMAIL VALIDATION */

    elseif(strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)){

        $msg = "Invalid email format!";
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

            $msg = "User already exists!";

        }else{

            $hash = password_hash($pass, PASSWORD_DEFAULT);

            try {

                $stmt = $conn->prepare("
                    INSERT INTO users(name,email,password)
                    VALUES(?,?,?)
                ");

                $stmt->execute([$name,$email,$hash]);

                $_SESSION['reset_message'] = "Account created. Please log in.";
                $_SESSION['reset_type'] = "success";

                header("Location: login.php");
                exit();

            } catch (PDOException $e) {

                // Two sign-ups with the same email at the same moment hit the UNIQUE index.
                if ($e->getCode() === '23000') {
                    $msg = "User already exists!";
                } else {
                    error_log('ExpenseFlow registration failed: ' . $e->getMessage());
                    $msg = "Could not create the account. Please try again.";
                }
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
<form method="POST">
    <?= csrf_field() ?>
    <?= ef_field('FULL NAME', 'name', 'usr', 'text', $name, ['placeholder' => 'Your name', 'required' => true, 'maxlength' => 100, 'autocomplete' => 'name']) ?>
    <?= ef_field('EMAIL', 'email', 'mail', 'email', $email, ['placeholder' => 'you@example.com', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
    <?= ef_field('PASSWORD', 'password', 'lock', 'password', '', ['placeholder' => 'Min. 8 characters', 'required' => true, 'autocomplete' => 'new-password', 'data-strength' => true]) ?>
    <div class="mt"><i></i><i></i><i></i><i></i></div>
    <p class="s3" style="margin:8px 0 14px;font-size:.78rem">Use 8+ characters with upper and lower case letters, a number and a symbol.</p>
    <?= ef_field('CONFIRM PASSWORD', 'confirm_password', 'lock', 'password', '', ['placeholder' => 'Repeat password', 'required' => true, 'autocomplete' => 'new-password']) ?>
    <button class="btn lg" style="width:100%" type="submit">Create account</button>
</form>
<div class="ft2">Already have an account? <a class="lnk" href="login.php">Log in</a></div>
<?php ef_auth_end(); ?>
