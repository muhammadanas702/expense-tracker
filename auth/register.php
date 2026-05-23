<?php

require_once "../config/db.php";

$msg = "";

$name = "";
$email = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);

    $pass = $_POST["password"];
    $cpass = $_POST["confirm_password"];

    /* EMAIL VALIDATION */

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){

        $msg = "Invalid email format!";
    }

    /* PASSWORD VALIDATION */

    elseif(!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&]).{6,}$/", $pass)){

        $msg = "Password must contain uppercase, lowercase, number & special character!";
    }

    /* PASSWORD MATCH */

    elseif($pass !== $cpass){

        $msg = "Passwords do not match!";
    }

    else{

        $check = $conn->prepare("
            SELECT id
            FROM users
            WHERE email=?
        ");

        $check->execute([$email]);

        if($check->rowCount() > 0){

            $msg = "User already exists!";

        }else{

            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("
                INSERT INTO users(name,email,password)
                VALUES(?,?,?)
            ");

            $stmt->execute([$name,$email,$hash]);

            header("Location: login.php");
            exit();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<head>

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
                <?php echo $msg; ?>
            </div>
        <?php } ?>

        <form method="POST">

            <input
                type="text"
                name="name"
                placeholder="Full Name"
                value="<?php echo htmlspecialchars($name ?? '') ?>"
                required
            >

            <input
                type="email"
                name="email"
                placeholder="Email Address"
                value="<?php echo htmlspecialchars($email ?? '') ?>"
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