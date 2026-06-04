<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once "../config/app.php";
require_once "../config/db.php";
require_once "../includes/CurrencyConverter.php";
require_once "../includes/logging.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
$user_id = $_SESSION["user_id"];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = trim($_POST['title']);
    $amount = (float)$_POST['amount'];
    $currency = strtoupper($_POST['currency']);
    $client_time = $_POST['client_local_time'] ?? null;
    
    $transaction_date = date('Y-m-d H:i:s'); // server time for the record, but log uses client time
    $year_month = date('Y-m', strtotime($transaction_date));

    $stmt = $conn->prepare("SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
    $stmt->execute([$user_id, $year_month]);
    $base = $stmt->fetchColumn();

    if (!$base) {
        $base = $currency;
        $insertBase = $conn->prepare("INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)");
        $insertBase->execute([$user_id, $year_month, $base]);
    }

    $insert = $conn->prepare("INSERT INTO `income` (`user_id`, `title`, `amount`, `amount_pkr`, `currency`, `transaction_date`) VALUES (?, ?, ?, ?, ?, ?)");
    $insert->execute([$user_id, $title, $amount, $amount, $currency, $transaction_date]);

    logAction(
    $conn,
    $user_id,
    'add_income',
    "Title: $title, Amount: $amount $currency",
    $client_time
);
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>Add Income - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/client-time.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .form-container { background: white; border-radius: 32px; padding: 2rem; width: 450px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; text-align: center; }
        label { display: block; font-weight: 500; color: #334155; margin: 1rem 0 0.3rem 0; }
        input, select { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; font-size: 1rem; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.8rem; border-radius: 40px; width: 100%; font-weight: 600; margin-top: 1.5rem; cursor: pointer; transition: 0.2s; }
        button:hover { background: linear-gradient(135deg, #0d5c55, #1e3a8a); transform: scale(0.98); }
        .back-link { display: block; text-align: center; margin-top: 1rem; color: #0f766e; text-decoration: none; }
    </style>
</head>
<body>
<div class="form-container">
    <h2>➕ Add Income</h2>
    <form method="POST" data-log>
        <label>Title</label>
        <input type="text" name="title" placeholder="e.g., Salary, Freelance" required>
        <label>Amount</label>
        <input type="number" step="any" name="amount" placeholder="0.00" required>
        <label>Currency</label>
        <select name="currency" required>
            <option value="PKR">Pakistani Rupee (PKR)</option>
            <option value="USD">US Dollar (USD)</option>
            <option value="EUR">Euro (EUR)</option>
            <option value="GBP">British Pound (GBP)</option>
            <option value="AED">UAE Dirham (AED)</option>
            <option value="SAR">Saudi Riyal (SAR)</option>
            <option value="KRW">South Korean Won (KRW)</option>
            <option value="INR">Indian Rupee (INR)</option>
            <option value="CAD">Canadian Dollar (CAD)</option>
            <option value="AUD">Australian Dollar (AUD)</option>
        </select>
        <button type="submit">Add Income</button>
    </form>
    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>