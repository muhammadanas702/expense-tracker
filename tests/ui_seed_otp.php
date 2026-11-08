<?php
// Test helper (dev server only): makes a demo user and a password-reset session whose emailed code is 123456, then
// opens the code page. ?cleanup=1 removes the demo user again.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/db.php';
secure_session_start();
$email = 'otp.demo@example.com';
if (isset($_GET['cleanup'])) {
    $conn->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
    echo 'cleaned';
    exit;
}
$st = $conn->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if (!$st->fetch()) {
    $conn->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)')->execute(['OTP Demo', $email, password_hash('Demo#Pass1234', PASSWORD_DEFAULT)]);
}
$_SESSION['reset_email'] = $email;
$_SESSION['reset_otp_hash'] = password_hash('123456', PASSWORD_DEFAULT);
$_SESSION['reset_otp_expiry'] = time() + 600;
$_SESSION['reset_otp_attempts'] = 0;
header('Location: ../auth/verify-otp.php');
