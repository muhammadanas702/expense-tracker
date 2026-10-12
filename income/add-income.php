<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/CurrencyConverter.php';
require_once __DIR__ . '/../includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$error = '';
$old = ['title' => '', 'amount' => '', 'currency' => null];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($old as $field => $default) {
        $value = $_POST[$field] ?? $default;
        $old[$field] = is_string($value) ? $value : $default;
    }

    $title    = clean_text($old['title'], 100);
    $amount   = parse_amount($old['amount']);
    $currency = normalize_currency($old['currency']);

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($title === null) {
        $error = "Please enter a title (up to 100 characters).";
    } elseif ($amount === null) {
        $error = "Please enter a valid amount greater than zero.";
    } elseif ($currency === null) {
        $error = "Please choose a supported currency.";
    } else {
        // Both the original amount and its PKR value are stored, so the entry keeps its own currency.
        $amount_pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
        $transaction_date = date('Y-m-d H:i:s');
        $year_month = date('Y-m');

        try {
            $conn->beginTransaction();

            // The first entry of a month decides that month's base currency.
            $stmt = $conn->prepare("SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
            $stmt->execute([$user_id, $year_month]);
            if (!$stmt->fetchColumn()) {
                $conn->prepare("INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)")
                    ->execute([$user_id, $year_month, $currency]);
            }

            $conn->prepare("INSERT INTO `income` (`user_id`, `title`, `amount`, `currency`, `amount_pkr`, `transaction_date`) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $transaction_date]);

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('ExpenseFlow add income failed: ' . $e->getMessage());
            $error = "Something went wrong. Please try again.";
        }

        if ($error === '') {
            logAction($conn, $user_id, 'add_income', "Title: $title, Amount: $amount $currency");
            $_SESSION['success'] = "Income added successfully!";
            header("Location: " . $base_url . "/dashboard.php");
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
    <title>Add Income | ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f4f7f9; min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { max-width: 500px; width: 100%; background: white; border-radius: 32px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.05); }
        h1 { font-size: 28px; margin-bottom: 24px; color: #0B2545; text-align: center; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; }
        input, select { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 16px; font-size: 16px; transition: 0.2s; }
        input:focus, select:focus { outline: none; border-color: #137A7F; box-shadow: 0 0 0 3px rgba(19,122,127,0.1); }
        button { width: 100%; padding: 14px; background: #137A7F; color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        button:hover { background: #0b5f63; transform: translateY(-2px); }
        .error { color: #dc2626; background: #fee2e2; padding: 12px; border-radius: 16px; margin-bottom: 20px; text-align: center; }
        .back-link-wrapper { text-align: center; margin-top: 20px; }
        .back-link { color: #137A7F; text-decoration: none; font-weight: 500; display: inline-block; }
        .back-link:hover { text-decoration: underline; }
        .info-note { background: #eef2f6; padding: 8px 12px; border-radius: 12px; font-size: 12px; color: #5F6C7D; margin-top: 16px; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h1>➕ Add Income</h1>
    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Title / Source</label>
            <input type="text" name="title" maxlength="100" value="<?= e($old['title']) ?>" required placeholder="e.g., Salary, Freelance, Gift">
        </div>
        <div class="form-group">
            <label>Amount</label>
            <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" required placeholder="Enter amount">
        </div>
        <div class="form-group">
            <label>Currency</label>
            <select name="currency" required>
            <?= currency_select_options($old['currency']) ?>
        </select>
        </div>
        <button type="submit">Add Income</button>
    </form>
    <div class="info-note">
        ⏱️ Transaction time is automatically recorded.
    </div>
    <div class="back-link-wrapper">
        <a href="<?= e($base_url) ?>/dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</div>
</body>
</html>