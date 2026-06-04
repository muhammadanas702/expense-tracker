<?php
session_start();
require_once "config/db.php";
require_once "includes/logging.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$client_time = $_GET['client_time'] ?? null;

if (isset($_GET["type"])) {
    $type = $_GET["type"];

    if ($type == "income") {
        $stmt = $conn->prepare("DELETE FROM income WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $stmt2 = $conn->prepare("DELETE FROM user_monthly_currency WHERE user_id = ?");
        $stmt2->execute([$user_id]);
        logAction(
    $conn,
    $user_id,
    'reset_data',
    "Reset type: income",
    $client_time
);
    }
    elseif ($type == "expense") {

        $stmt = $conn->prepare("DELETE FROM expenses WHERE user_id = ?");
        $stmt->execute([$user_id]);
        logAction(
        $conn,
        $user_id,
        'reset_data',
        "Reset type: expense",
        $client_time
);
    }
    elseif ($type == "all") {
        $stmt = $conn->prepare("DELETE FROM income WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $stmt = $conn->prepare("DELETE FROM expenses WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $stmt = $conn->prepare("DELETE FROM user_monthly_currency WHERE user_id = ?");
        $stmt->execute([$user_id]);
        logAction(
    $conn,
    $user_id,
    'reset_data',
    "Reset type: all",
    $client_time
);
    }
}

header("Location: dashboard.php");
exit();
?>