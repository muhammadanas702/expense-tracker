<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Check if user is admin (looked up again on every request, never trusted from the session)
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$is_admin = $stmt->fetchColumn();
if (!$is_admin) {
    http_response_code(403);
    exit("Access denied. Admins only.");
}
