<?php
session_start();
require_once "config/db.php";
require_once "includes/logging.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$client_time = $_GET['client_time'] ?? null;

$stmt = $conn->prepare("DELETE FROM income WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);

logAction($user_id, 'delete_income', "Deleted income ID: $id", $client_time);

header("Location: dashboard.php");
exit();
?>