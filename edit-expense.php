<?php
session_start();
require_once "config/db.php";
require_once "includes/logging.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    die("Expense not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST['title']);
    $amount = (float)$_POST['amount'];
    $currency = $_POST['currency'];
    $category = $_POST['category'];
    $transaction_date = $_POST['transaction_date'];
    $client_time = $_POST['client_local_time'] ?? null;

    $update = $conn->prepare("UPDATE expenses SET title = ?, amount = ?, currency = ?, category = ?, transaction_date = ? WHERE id = ? AND user_id = ?");
    $update->execute([$title, $amount, $currency, $category, $transaction_date, $id, $user_id]);

    logAction($user_id, 'edit_expense', "Updated expense ID: $id", $client_time);

    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Expense</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="/assets/client-time.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .form-card { background: white; border-radius: 32px; padding: 2rem; max-width: 500px; width: 100%; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; }
        label { display: block; margin-top: 1rem; font-weight: 500; color: #334155; }
        input, select { width: 100%; padding: 0.7rem; margin-top: 0.3rem; border: 1px solid #cbd5e1; border-radius: 20px; }
        button { margin-top: 1.5rem; background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .cancel { background: #e2e8f0; color: #1e293b; margin-top: 0.5rem; text-align: center; display: block; text-decoration: none; padding: 0.7rem; border-radius: 40px; }
    </style>
</head>
<body>
<div class="form-card">
    <h2>✏️ Edit Expense</h2>
    <form method="POST" data-log>
        <label>Title</label>
        <input type="text" name="title" value="<?= htmlspecialchars($expense['title']) ?>" required>
        <label>Amount </label>
        <input type="number" step="0.01" name="amount" value="<?= $expense['amount'] ?>" required>
        <label>Currency</label>
        <select name="currency">
            <option value="PKR" <?= $expense['currency']=='PKR'?'selected':'' ?>>PKR</option>
            <option value="USD" <?= $expense['currency']=='USD'?'selected':'' ?>>USD</option>
            <option value="EUR" <?= $expense['currency']=='EUR'?'selected':'' ?>>EUR</option>
            <option value="GBP" <?= $expense['currency']=='GBP'?'selected':'' ?>>GBP</option>
            <option value="AED" <?= $expense['currency']=='AED'?'selected':'' ?>>AED</option>
            <option value="SAR" <?= $expense['currency']=='SAR'?'selected':'' ?>>SAR</option>
            <option value="KRW" <?= $expense['currency']=='KRW'?'selected':'' ?>>KRW</option>
        </select>
        <label>Category</label>
        <input type="text" name="category" value="<?= htmlspecialchars($expense['category']) ?>" required>
        <label>Date</label>
        <input type="datetime-local" name="transaction_date" value="<?= date('Y-m-d\TH:i', strtotime($expense['transaction_date'])) ?>" required>
        <button type="submit">Update Expense</button>
        <a href="dashboard.php" class="cancel">Cancel</a>
    </form>
</div>
</body>
</html>