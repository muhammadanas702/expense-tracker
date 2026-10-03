<?php
session_start();
require_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: forgot-password.php");
    exit();
}

$email = trim($_POST['email']);

// Check if email exists – use 'name' column
$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['reset_message'] = "No account found with that email address.";
    $_SESSION['reset_type'] = "error";
    header("Location: forgot-password.php");
    exit();
}

// Generate unique token
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Store token in database – using 'token_expiry' (not reset_expires)
$update = $conn->prepare("UPDATE users SET reset_token = ?, token_expiry = ? WHERE id = ?");
$update->execute([$token, $expires, $user['id']]);

// Build reset link
$reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/auth/reset-password.php?token=" . $token;

// --- Send email ---
$to = $email;
$subject = "Reset your ExpenseFlow password";
$message = "Hello " . $user['name'] . ",\n\n";
$message .= "You requested to reset your password. Click the link below:\n\n";
$message .= $reset_link . "\n\n";
$message .= "This link expires in 1 hour.\n\n";
$message .= "If you didn't request this, ignore this email.\n\n";
$message .= "– ExpenseFlow Team";
$headers = "From: noreply@expenseflow.com\r\n";
$mailSent = mail($to, $subject, $message, $headers);

// For localhost testing (when mail() fails), log the link to a file
if (!$mailSent) {
    $log = __DIR__ . "/reset_links.log";
    file_put_contents($log, date('Y-m-d H:i:s') . " - $email - $reset_link\n", FILE_APPEND);
    $debugInfo = " (mail not sent on localhost – check reset_links.log)";
} else {
    $debugInfo = "";
}

$_SESSION['reset_message'] = "Reset link sent to your email address. Check your inbox (and spam).$debugInfo";
$_SESSION['reset_type'] = "success";
header("Location: forgot-password.php");
exit();