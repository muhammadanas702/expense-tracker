<?php

if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {

    // LOCAL XAMPP
    $host = "localhost";
    $dbname = "expense_tracker";
    $username = "root";
    $password = "ipc@umt";

} else {

    // LIVE (InfinityFree)
    $host = "sql103.infinityfree.com";
    $dbname = "if0_41994702_expenseflow";
    $username = "if0_41994702";
    $password = "Anasali001";
}

try {
    $conn = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}