<?php
session_start();
require_once "../config/db.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'src/Exception.php';
require_once 'src/PHPMailer.php';
require_once 'src/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: forgot-password.php");
    exit();
}

$email = trim($_POST['email']);

// Check if email exists
$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['reset_message'] = "No account found with that email address.";
    $_SESSION['reset_type'] = "error";
    header("Location: forgot-password.php");
    exit();
}

// Generate 6-digit OTP
$otp = rand(100000, 999999);
$_SESSION['reset_otp'] = $otp;
$_SESSION['reset_email'] = $email;
$_SESSION['reset_otp_expiry'] = time() + 600; // 10 minutes

// Send email via SMTP
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'expenseflow82@gmail.com';     // YOUR GMAIL
    $mail->Password   = 'sjng iiqr phgn epwu';       // APP PASSWORD (spaces removed? No, keep as is)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('expenseflow82@gmail.com', 'ExpenseFlow');
    $mail->addAddress($email, $user['name']);

    $mail->isHTML(true);
    $mail->Subject = 'Password Reset OTP';
    $mail->Body    = "Hello {$user['name']},<br><br>Your OTP to reset your password is: <b>$otp</b><br><br>This OTP is valid for 10 minutes.<br><br>If you didn't request this, ignore this email.<br><br>– ExpenseFlow Team";
    $mail->AltBody = "Your OTP to reset your password is: $otp";

    $mail->send();
    $_SESSION['reset_message'] = "A 6-digit OTP has been sent to your email address.";
    $_SESSION['reset_type'] = "success";
    header("Location: verify-otp.php");
    exit();
} catch (Exception $e) {
    $_SESSION['reset_message'] = "Mail error: {$mail->ErrorInfo}";
    $_SESSION['reset_type'] = "error";
    header("Location: forgot-password.php");
    exit();
}
?>