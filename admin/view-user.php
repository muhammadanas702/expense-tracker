<?php
require_once "auth.php";
require_once "../config/db.php";

$id = $_GET['id'] ?? 0;
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found.");

// Get their transactions
$incomes = $conn->prepare("SELECT * FROM income WHERE user_id = ? ORDER BY transaction_date DESC");
$incomes->execute([$id]);
$expenses = $conn->prepare("SELECT * FROM expenses WHERE user_id = ? ORDER BY transaction_date DESC");
$expenses->execute([$id]);
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/expense-tracker/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>View User - <?= htmlspecialchars($user['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; padding: 2rem; }
        .container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 28px; padding: 2rem; border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .back { display: inline-block; margin-top: 1rem; color: #0f766e; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>User: <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['email']) ?>)</h2>
    <h3>Income Records</h3>
    <table>
        <thead><tr><th>Title</th><th>Amount (PKR)</th><th>Currency</th><th>Date</th></tr></thead>
        <tbody>
        <?php while($row = $incomes->fetch(PDO::FETCH_ASSOC)): ?>
            <tr><td><?= htmlspecialchars($row['title']) ?></td><td><?= number_format($row['amount_pkr'],2) ?></td><td><?= $row['currency'] ?></td><td><?= $row['transaction_date'] ?></td></tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <h3>Expense Records</h3>
    <table>
        <thead><tr><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>Date</th></tr></thead>
        <tbody>
        <?php while($row = $expenses->fetch(PDO::FETCH_ASSOC)): ?>
            <tr><td><?= htmlspecialchars($row['title']) ?></td><td><?= htmlspecialchars($row['category']) ?></td><td><?= number_format($row['amount'],2) ?></td><td><?= $row['currency'] ?></td><td><?= $row['transaction_date'] ?></td></tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <a href="index.php" class="back">← Back to Admin Dashboard</a>
</div>
</body>
</html>