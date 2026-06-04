<?php
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
        $base = $currency;
        $insertBase = $conn->prepare("INSERT INTO user_monthly_currency (user_id, year_month, base_currency) VALUES (?, ?, ?)");
        $insertBase->execute([$user_id, $year_month, $base]);
    }

    $insert = $conn->prepare("INSERT INTO `expenses` (`user_id`, `title`, `amount`, `currency`, `category`, `transaction_date`) VALUES (?, ?, ?, ?, ?, ?)");
    $insert->execute([$user_id, $title, $amount, $currency, $category, $transaction_date]);

    logAction($conn, $user_id, 'add_expense', "Title: $title, Category: $category, Amount: $amount $currency", $client_time);
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/responsive.css">

<head>
    <link rel="icon" type="image/png" href="/favicon.png">
    <meta charset="UTF-8">
    <title>Add Expense - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/client-time.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 2rem; }
        .form-container { background: white; border-radius: 32px; padding: 2rem; width: 450px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0B2545; text-align: center; font-size: 28px; }
        label { display: block; font-weight: 500; color: #2c3e50; margin: 1rem 0 0.3rem 0; }
        input, select { width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 16px; font-size: 1rem; transition: 0.2s; }
        input:focus, select:focus { outline: none; border-color: #137A7F; box-shadow: 0 0 0 3px rgba(19,122,127,0.1); }
        /* Increased margin for button and other elements */
        button { width: 100%; padding: 14px; background: #137A7F; color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.2s; margin-top: 1.5rem; }
        button:hover { background: #0b5f63; transform: translateY(-2px); }
        .error { background: #fee2e2; color: #b91c1c; padding: 12px; border-radius: 16px; margin-bottom: 1rem; text-align: center; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; color: #137A7F; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        #other_category_div { margin-top: 0.5rem; display: none; }
        /* ensure spacing after category select */
        .form-group-last { margin-bottom: 0; }
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
            <optgroup label="Major Currencies">
                <option value="USD">🇺🇸 US Dollar (USD)</option>
                <option value="EUR">🇪🇺 Euro (EUR)</option>
                <option value="GBP">🇬🇧 British Pound (GBP)</option>
                <option value="PKR">🇵🇰 Pakistani Rupee (PKR)</option>
                <option value="INR">🇮🇳 Indian Rupee (INR)</option>
                <option value="AED">🇦🇪 UAE Dirham (AED)</option>
                <option value="SAR">🇸🇦 Saudi Riyal (SAR)</option>
                <option value="KRW">🇰🇷 South Korean Won (KRW)</option>
                <option value="JPY">🇯🇵 Japanese Yen (JPY)</option>
                <option value="CNY">🇨🇳 Chinese Yuan (CNY)</option>
                <option value="CAD">🇨🇦 Canadian Dollar (CAD)</option>
                <option value="AUD">🇦🇺 Australian Dollar (AUD)</option>
                <option value="CHF">🇨🇭 Swiss Franc (CHF)</option>
                <option value="NZD">🇳🇿 New Zealand Dollar (NZD)</option>
                <option value="SGD">🇸🇬 Singapore Dollar (SGD)</option>
                <option value="MYR">🇲🇾 Malaysian Ringgit (MYR)</option>
                <option value="THB">🇹🇭 Thai Baht (THB)</option>
                <option value="VND">🇻🇳 Vietnamese Dong (VND)</option>
                <option value="PHP">🇵🇭 Philippine Peso (PHP)</option>
                <option value="IDR">🇮🇩 Indonesian Rupiah (IDR)</option>
                <option value="BDT">🇧🇩 Bangladeshi Taka (BDT)</option>
                <option value="LKR">🇱🇰 Sri Lankan Rupee (LKR)</option>
                <option value="NPR">🇳🇵 Nepalese Rupee (NPR)</option>
                <option value="AFN">🇦🇫 Afghan Afghani (AFN)</option>
            </optgroup>
            <optgroup label="Other Major Currencies">
                <option value="TRY">🇹🇷 Turkish Lira (TRY)</option>
                <option value="RUB">🇷🇺 Russian Ruble (RUB)</option>
                <option value="BRL">🇧🇷 Brazilian Real (BRL)</option>
                <option value="ZAR">🇿🇦 South African Rand (ZAR)</option>
                <option value="MXN">🇲🇽 Mexican Peso (MXN)</option>
                <option value="SEK">🇸🇪 Swedish Krona (SEK)</option>
                <option value="NOK">🇳🇴 Norwegian Krone (NOK)</option>
                <option value="DKK">🇩🇰 Danish Krone (DKK)</option>
                <option value="PLN">🇵🇱 Polish Zloty (PLN)</option>
                <option value="HKD">🇭🇰 Hong Kong Dollar (HKD)</option>
                <option value="ILS">🇮🇱 Israeli Shekel (ILS)</option>
                <option value="KWD">🇰🇼 Kuwaiti Dinar (KWD)</option>
                <option value="BHD">🇧🇭 Bahraini Dinar (BHD)</option>
                <option value="OMR">🇴🇲 Omani Rial (OMR)</option>
                <option value="QAR">🇶🇦 Qatari Riyal (QAR)</option>
                <option value="EGP">🇪🇬 Egyptian Pound (EGP)</option>
            </optgroup>
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

        <!-- Submit button now has proper top margin -->
        <button type="submit">Add Expense</button>
    </form>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>