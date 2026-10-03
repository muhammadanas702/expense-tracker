<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "config/db.php";
require_once "includes/logging.php";
require_once "includes/CurrencyConverter.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_base = strtoupper(trim($_POST['new_base_currency'] ?? ''));
    $year_month = $_POST['year_month'] ?? '';
    $month = $_POST['month'] ?? '';
    $year = $_POST['year'] ?? '';
    $client_time = $_POST['client_local_time'] ?? null;
    
    if ($new_base && $year_month && preg_match('/^\d{4}-\d{2}$/', $year_month)) {
        $stmt = $conn->prepare("SELECT 1 FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
        $stmt->execute([$user_id, $year_month]);
        if ($stmt->fetchColumn()) {
            $update = $conn->prepare("UPDATE `user_monthly_currency` SET `base_currency` = ? WHERE `user_id` = ? AND `year_month` = ?");
            $update->execute([$new_base, $user_id, $year_month]);
            
            // ✅ Pass $conn as first argument
            logAction($conn, $user_id, 'change_base_currency', "Changed base currency to $new_base for $year_month", $client_time);
            
            $_SESSION['success'] = "Base currency changed to $new_base. All totals recalculated.";
        } else {
            $_SESSION['error'] = "No base currency found for this month.";
        }
    } else {
        $_SESSION['error'] = "Invalid request.";
    }
}

$redirect = "dashboard.php";
if ($month && $year) {
    $redirect .= "?month=" . urlencode($month) . "&year=" . urlencode($year);
}
header("Location: $redirect");
exit();
?>