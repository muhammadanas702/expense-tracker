<?php
// Stops one repeating item. POST + CSRF only; the item must belong to the signed-in user.
require_once __DIR__ . '/../../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/logging.php';
require_once __DIR__ . '/lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
require_post_csrf();

$user_id = (int) $_SESSION['user_id'];
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

personal_ensure_schema($conn);
$stmt = $conn->prepare('UPDATE `recurring_items` SET `active` = 0 WHERE `id` = ? AND `user_id` = ?');
$stmt->execute([$id, $user_id]);
if ($stmt->rowCount() > 0) {
    logAction($conn, $user_id, 'stop_recurring', "Stopped repeating item ID: $id");
    $_SESSION['success'] = 'Repeating item stopped. Entries already added stay as they are.';
}
header('Location: ' . $base_url . '/dashboard.php');
exit();
