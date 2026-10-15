<?php
require_once "auth.php";
require_once __DIR__ . '/../config/db.php';

// Deleting a user must be a POST with a valid CSRF token (never a plain link).
require_post_csrf();

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit("Invalid user.");
}

// Prevent admin from deleting themselves
if ($id === (int) $_SESSION['user_id']) {
    exit("You cannot delete your own admin account.");
}

$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    exit("User not found.");
}

// Delete everything that belongs to the user, then the user (all or nothing)
require_once __DIR__ . '/../modules/personal/lib.php';
personal_ensure_schema($conn);
require_once __DIR__ . '/../modules/profile/lib.php';
profile_ensure_schema($conn); // makes sure the extra tables exist before they are cleaned
$conn->beginTransaction();
try {
    foreach (['income', 'expenses', 'user_monthly_currency', 'user_logs', 'recurring_items', 'user_settings'] as $table) {
        $conn->prepare("DELETE FROM `$table` WHERE user_id = ?")->execute([$id]);
    }
    $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    error_log('ExpenseFlow admin delete user failed: ' . $e->getMessage());
    http_response_code(500);
    exit("Could not delete the user. Nothing was changed.");
}

logAction($conn, $_SESSION['user_id'], 'admin_delete_user', "Deleted user ID: $id");

header("Location: index.php");
exit();
