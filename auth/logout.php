<?php
session_start();
require_once "../config/db.php";
require_once "../includes/logging.php";

$client_time = $_GET['client_time'] ?? null;
if (isset($_SESSION["user_id"])) {
    logAction($_SESSION["user_id"], 'logout', "User logged out", $client_time);
}
session_destroy();
header("Location: login.php");
exit();
?>