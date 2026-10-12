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

?>

<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<head>

<?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>

<meta charset="UTF-8">

<title>Register</title>

<link rel="stylesheet"href="../assets/auth.css">

</head>

<body>

<div class="auth-wrapper">

    <div class="card">

        <div class="logo">
            Expense<span>Flow</span>
        </div>

        <h2>Create Account</h2>

        <p class="subtitle">
            Start your smart finance journey
        </p>

        <?php if(!empty($msg)) { ?>
            <div class="error">
                <?= e($msg) ?>
            </div>
        <?php } ?>

        <form method="POST">

            <?= csrf_field() ?>

            <input
                type="text"
                name="name"
                placeholder="Full Name"
                maxlength="100"
                value="<?= e($name) ?>"
                required
            >

            <input
                type="email"
                name="email"
                placeholder="Email Address"
                maxlength="190"
                value="<?= e($email) ?>"
                required
            >

            <input
                type="password"
                name="password"
                placeholder="Password"
                required
            >

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm Password"
                required
            >

            <button type="submit">
                Create Account
            </button>

        </form>

        <div class="auth-links">

            Already have an account?

            <a href="login.php">
                Login
            </a>

        </div>

    </div>

</div>

</body>
</html>
