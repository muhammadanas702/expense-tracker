<?php

session_start();

require_once "../config/db.php";

if(!isset($_SESSION["user_id"])){

    header("Location: login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

/* DELETE ALL USER DATA */

$conn->prepare("
    DELETE FROM income
    WHERE user_id=?
")->execute([$user_id]);

$conn->prepare("
    DELETE FROM expenses
    WHERE user_id=?
")->execute([$user_id]);

$conn->prepare("
    DELETE FROM users
    WHERE id=?
")->execute([$user_id]);

session_destroy();

header("Location: login.php");

exit();

?>