<?php
require_once "auth.php";
require_once "../config/db.php";

$id = $_GET['id'] ?? 0;

// Prevent admin from deleting themselves
if ($id == $_SESSION['user_id']) {
    die("You cannot delete your own admin account.");
}

// Delete user's income, expenses, then user
$conn->prepare("DELETE FROM income WHERE user_id = ?")->execute([$id]);
$conn->prepare("DELETE FROM expenses WHERE user_id = ?")->execute([$id]);
$conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

header("Location: index.php");
exit();
?>