<?php
$host = 'localhost';
$dbname = 'expense_tracker';   // your database name
$username = 'root';            // your DB username
$password = 'ipc@umt';                // your DB password

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>