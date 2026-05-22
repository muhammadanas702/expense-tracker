<?php
session_start();
require_once "../config/db.php";
require_once "../includes/logging.php";

$error = "";
$email = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $client_time = $_POST['client_local_time'] ?? null;

    $stmt = $conn->prepare("SELECT * FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user){
        if(password_verify($password, $user["password"])){
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];
            logAction($user["id"], 'login', "User logged in", $client_time);
            header("Location: ../dashboard.php");
            exit();
        } else {
            $error = "Wrong password!";
        }
    } else {
        $error = "Invalid email or password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/expense-tracker/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="/expense-tracker/assets/auth.css">
    <script src="/expense-tracker/assets/client-time.js"></script>

</head>
<body>
<div class="auth-wrapper">
    <div class="card">
        <div class="logo">Expense<span>Flow</span></div>
        <h2>Welcome Back</h2>
        <p class="subtitle">Login to continue managing your finances</p>
        <?php if(!empty($error)) { ?>
            <div class="error"><?php echo $error; ?></div>
        <?php } ?>
        <form method="POST" data-log>
            <input type="email" name="email" placeholder="Enter Email" value="<?php echo htmlspecialchars($email ?? '') ?>" required>
            <input type="password" name="password" placeholder="Enter Password" required>
            <div style="text-align: right; margin-top: -0.5rem; margin-bottom: 1rem;">
                <a href="forgot-password.php" style="font-size: 0.85rem; color: #0f766e; text-decoration: none;">Forgot password?</a>
            </div>
            <button type="submit">Login</button>
        </form>
        <div class="links">
            Don't have an account? <a href="register.php">Register</a>
        </div>
    </div>
</div>
</body>
</html>