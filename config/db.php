<?php

//for public host
$host = "sql103.infinityfree.com";
$dbname = "if0_41994702_expenseflow";
$username = "if0_41994702";
$password = "Anasali001";

//for local host
/*$dbname = "expense_tracker";
$host = "localhost";
$username = "root";
$password = "ipc@umt";*/

try {

    $conn = new PDO(
        "mysql:host=$host;dbname=$dbname",
        $username,
        $password
    );

    $conn->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch(PDOException $e) {

    die("Connection failed: " . $e->getMessage());
}
?>