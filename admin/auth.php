<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
// Check if user is admin
require_once "../config/db.php";
$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$is_admin = $stmt->fetchColumn();
if (!$is_admin) {
    die("Access denied. Admins only.");
}
?>