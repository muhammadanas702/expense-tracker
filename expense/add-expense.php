<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/CurrencyConverter.php';
require_once __DIR__ . '/../includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
$user_id = (int) $_SESSION["user_id"];

$allowed_categories = ['Food', 'Transport', 'Shopping', 'Entertainment', 'Bills', 'Healthcare', 'Education', 'Other'];
$error = '';
$old = ['title' => '', 'amount' => '', 'currency' => null, 'category' => 'Food', 'other_category' => ''];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($old as $field => $default) {
        $value = $_POST[$field] ?? $default;
        $old[$field] = is_string($value) ? $value : $default;
    }

    $title    = clean_text($old['title'], 100);
    $amount   = parse_amount($old['amount']);
    $currency = normalize_currency($old['currency']);
    $category = $old['category'];

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($title === null) {
        $error = "Please enter a title (up to 100 characters).";
    } elseif ($amount === null) {
        $error = "Please enter a valid amount greater than zero.";
    } elseif ($currency === null) {
        $error = "Please choose a supported currency.";
    } elseif (!in_array($category, $allowed_categories, true)) {
        $error = "Please choose a valid category.";
    } else {
        if ($category === 'Other') {
            $custom = clean_text($old['other_category'], 50);
            if ($custom !== null) {
                $category = $custom;
            }
        }

        // The PKR value is saved together with the expense, so totals do not shift when rates change.
        $amount_pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
        $transaction_date = date('Y-m-d H:i:s');
        $year_month = date('Y-m');

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
            $stmt->execute([$user_id, $year_month]);
            if (!$stmt->fetchColumn()) {
                $conn->prepare("INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)")
                    ->execute([$user_id, $year_month, $currency]);
            }

            $conn->prepare("INSERT INTO `expenses` (`user_id`, `title`, `amount`, `currency`, `amount_pkr`, `category`, `transaction_date`) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $category, $transaction_date]);

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('ExpenseFlow add expense failed: ' . $e->getMessage());
            $error = "Something went wrong. Please try again.";
        }

        if ($error === '') {
            logAction($conn, $user_id, 'add_expense', "Title: $title, Category: $category, Amount: $amount $currency");
            $_SESSION['success'] = "Expense added successfully!";
            header("Location: ../dashboard.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .form-container { background: white; border-radius: 32px; padding: 2rem; width: 450px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0B2545; text-align: center; font-size: 28px; }
        label { display: block; font-weight: 500; color: #2c3e50; margin: 1rem 0 0.3rem 0; }
        input, select { width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 16px; font-size: 1rem; transition: 0.2s; }
        input:focus, select:focus { outline: none; border-color: #137A7F; box-shadow: 0 0 0 3px rgba(19,122,127,0.1); }
        button { width: 100%; padding: 14px; background: #137A7F; color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.2s; margin-top: 1.5rem; }
        button:hover { background: #0b5f63; transform: translateY(-2px); }
        .error { background: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 16px; margin-bottom: 1rem; text-align: center; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; color: #137A7F; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        #other_category_div { margin-top: 0.5rem; display: none; }
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
        document.addEventListener('DOMContentLoaded', toggleOtherCategory);
    </script>
</head>
<body>
<div class="form-container">
    <h2>➖ Add Expense</h2>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <label>Title</label>
        <input type="text" name="title" maxlength="100" value="<?= e($old['title']) ?>" placeholder="e.g., Groceries, Rent" required>

        <label>Amount</label>
        <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" placeholder="0.00" required>

        <label>Currency</label>
        <select name="currency" required>
            <?= currency_select_options($old['currency']) ?>
        </select>

        <label>Category</label>
        <select name="category" id="category" onchange="toggleOtherCategory()" required>
            <option value="Food"<?= $old['category'] === 'Food' ? ' selected' : '' ?>>🍔 Food</option>
            <option value="Transport"<?= $old['category'] === 'Transport' ? ' selected' : '' ?>>🚗 Transport</option>
            <option value="Shopping"<?= $old['category'] === 'Shopping' ? ' selected' : '' ?>>🛍️ Shopping</option>
            <option value="Entertainment"<?= $old['category'] === 'Entertainment' ? ' selected' : '' ?>>🎬 Entertainment</option>
            <option value="Bills"<?= $old['category'] === 'Bills' ? ' selected' : '' ?>>💡 Bills</option>
            <option value="Healthcare"<?= $old['category'] === 'Healthcare' ? ' selected' : '' ?>>🏥 Healthcare</option>
            <option value="Education"<?= $old['category'] === 'Education' ? ' selected' : '' ?>>📚 Education</option>
            <option value="Other"<?= $old['category'] === 'Other' ? ' selected' : '' ?>>🔘 Other</option>
        </select>

        <div id="other_category_div">
            <label>Other Category Name</label>
            <input type="text" name="other_category" maxlength="50" value="<?= e($old['other_category']) ?>" placeholder="e.g., Gym, Subscription">
        </div>

        <button type="submit">Add Expense</button>
    </form>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>