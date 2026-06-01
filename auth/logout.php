<?php
session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/logging.php";

if (isset($_SESSION["user_id"])) {

    $user_id = $_SESSION["user_id"];

    // log before destroying session
    logAction($conn, $user_id, 'logout', 'User logged out', date('Y-m-d H:i:s'));
}

// destroy session
session_unset();
session_destroy();

// redirect to login
header("Location: login.php");
exit();
?>