<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/CurrencyConverter.php';
require_once __DIR__ . '/includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$stmt = $conn->prepare("SELECT * FROM income WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$income = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$income) {
    http_response_code(404);
    exit("Income record not found.");
}

$error = '';
$form = [
    'title'    => (string) $income['title'],
    'amount'   => (string) $income['amount'],
    'currency' => strtoupper(trim((string) ($income['currency'] ?? 'PKR'))),
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($form as $field => $current) {
        $value = $_POST[$field] ?? $current;
        $form[$field] = is_string($value) ? $value : $current;
    }

    $title    = clean_text($form['title'], 100);
    $amount   = parse_amount($form['amount']);
    $currency = normalize_currency($form['currency']);

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($title === null) {
        $error = "Please enter a title (up to 100 characters).";
    } elseif ($amount === null) {
        $error = "Please enter a valid amount greater than zero.";
    } elseif ($currency === null) {
        $error = "Please choose a supported currency.";
    } else {
        // Keep the PKR value saved with the income unless the amount or currency really changed.
        $moneyChanged = $income['amount_pkr'] === null
            || abs($amount - (float) $income['amount']) > 0.004
            || $currency !== strtoupper((string) ($income['currency'] ?? 'PKR'));
        $amount_pkr = $moneyChanged
            ? round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2)
            : (float) $income['amount_pkr'];

        // The transaction date is kept as it was.
        $update = $conn->prepare("UPDATE income SET title = ?, amount = ?, currency = ?, amount_pkr = ? WHERE id = ? AND user_id = ?");
        $update->execute([$title, $amount, $currency, $amount_pkr, $id, $user_id]);

        logAction($conn, $user_id, 'edit_income', "Updated income ID: $id");

        $_SESSION['success'] = "Income updated.";
        header("Location: dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Income</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .form-card { background: white; border-radius: 32px; padding: 2rem; max-width: 500px; width: 100%; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; }
        label { display: block; margin-top: 1rem; font-weight: 500; color: #334155; }
        input, select { width: 100%; padding: 0.7rem; margin-top: 0.3rem; border: 1px solid #cbd5e1; border-radius: 20px; font-family: inherit; }
        button { margin-top: 1.5rem; background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .cancel { background: #e2e8f0; color: #1e293b; margin-top: 0.5rem; text-align: center; display: block; text-decoration: none; padding: 0.7rem; border-radius: 40px; }
        .error { background: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 16px; margin-bottom: 1rem; text-align: center; }
        .info-note { font-size: 12px; color: #6b7280; margin-top: 8px; text-align: center; }
    </style>
</head>
<body>
<div class="form-card">
    <h2>✏️ Edit Income</h2>
    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <label>Title</label>
        <input type="text" name="title" maxlength="100" value="<?= e($form['title']) ?>" required>

        <label>Amount</label>
        <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($form['amount']) ?>" required>

        <label>Currency</label>
        <select name="currency" required>
            <?= currency_select_options($form['currency']) ?>
        </select>

        <div class="info-note">⏱️ Transaction date remains unchanged (original timestamp preserved).</div>
        <button type="submit">Update Income</button>
        <a href="dashboard.php" class="cancel">Cancel</a>
    </form>
</div>
</body>
</html>