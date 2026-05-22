<?php
session_start();
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
    $category = trim($_POST['category']);
    if ($category === "Other" && !empty($_POST['other_category'])) {
        $category = trim($_POST['other_category']);
    }
    $client_time = $_POST['client_local_time'] ?? null;
    
    $transaction_date = date('Y-m-d H:i:s');
    $year_month = date('Y-m', strtotime($transaction_date));

    $stmt = $conn->prepare("SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
    $stmt->execute([$user_id, $year_month]);
    $base = $stmt->fetchColumn();

    if (!$base) {
        $_SESSION['error'] = "Please add at least one income for this month before adding expenses.";
        header("Location: add-expense.php");
        exit();
    }

    $insert = $conn->prepare("INSERT INTO `expenses` (`user_id`, `title`, `amount`, `currency`, `category`, `transaction_date`) VALUES (?, ?, ?, ?, ?, ?)");
    $insert->execute([$user_id, $title, $amount, $currency, $category, $transaction_date]);

    logAction($user_id, 'add_expense', "Title: $title, Category: $category, Amount: $amount $currency", $client_time);
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/expense-tracker/assets/responsive.css">

<head>
    <meta charset="UTF-8">
    <title>Add Expense - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/expense-tracker/assets/client-time.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .form-container { background: white; border-radius: 32px; padding: 2rem; width: 450px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; text-align: center; }
        label { display: block; font-weight: 500; color: #334155; margin: 1rem 0 0.3rem 0; }
        input, select { width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; font-size: 1rem; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.8rem; border-radius: 40px; width: 100%; font-weight: 600; margin-top: 1.5rem; cursor: pointer; transition: 0.2s; }
        button:hover { background: linear-gradient(135deg, #0d5c55, #1e3a8a); transform: scale(0.98); }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem; border-radius: 20px; margin-bottom: 1rem; text-align: center; }
        .back-link { display: block; text-align: center; margin-top: 1rem; color: #0f766e; text-decoration: none; }
        #other_category_div { margin-top: 10px; display: none; }
    </style>
    <script>
        function toggleOtherCategory() {
            var catSelect = document.getElementById('category');
            var otherDiv = document.getElementById('other_category_div');
            if (catSelect.value === 'Other') {
                otherDiv.style.display = 'block';
            } else {
                otherDiv.style.display = 'none';
            }
        }
    </script>
</head>
<body>
<div class="form-container">
    <h2>➖ Add Expense</h2>

    <?php if(isset($_SESSION['error'])): ?>
        <div class="error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form method="POST" data-log>
        <label>Title</label>
        <input type="text" name="title" placeholder="e.g., Groceries, Rent" required>
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
        <label>Category</label>
        <select name="category" id="category" onchange="toggleOtherCategory()" required>
            <option value="Food">🍔 Food</option>
            <option value="Transport">🚗 Transport</option>
            <option value="Shopping">🛍️ Shopping</option>
            <option value="Entertainment">🎬 Entertainment</option>
            <option value="Bills">💡 Bills</option>
            <option value="Healthcare">🏥 Healthcare</option>
            <option value="Education">📚 Education</option>
            <option value="Other">🔘 Other</option>
        </select>
        <div id="other_category_div">
            <label>Other Category Name</label>
            <input type="text" name="other_category" placeholder="e.g., Gym, Subscription">
        </div>
        <button type="submit">Add Expense</button>
    </form>
    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>