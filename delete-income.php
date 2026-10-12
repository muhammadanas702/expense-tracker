<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

// Deleting must be a POST with a valid CSRF token (never a plain link).
require_post_csrf();

$user_id = (int) $_SESSION["user_id"];
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$stmt = $conn->prepare("DELETE FROM income WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);

if ($stmt->rowCount() > 0) {
    logAction($conn, $user_id, 'delete_income', "Deleted income ID: $id");
}

header("Location: dashboard.php");
exit();
