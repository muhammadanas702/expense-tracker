<?php
$host = "localhost";
$dbname = "expense_tracker";
$username = "root";
$password = "ipc@umt";

$conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);