<?php
session_start();
require_once "../config/app.php";
require_once "../config/db.php";
require_once "../includes/CurrencyConverter.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = trim($_POST['title'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $currency = strtoupper(trim($_POST['currency'] ?? 'PKR'));
    
    if (empty($title) || $amount <= 0) {
        $error = "Please enter valid title and amount.";
    } else {
        $amount_pkr = CurrencyConverter::convert($amount, $currency, 'PKR');
        $transaction_date = date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO income (user_id, title, amount, amount_pkr, transaction_date) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute([$user_id, $title, $amount, $amount_pkr, $transaction_date])) {
            $_SESSION['success'] = "Income added successfully!";
            header("Location: " . $base_url . "/dashboard.php");
            exit();
        } else {
            $error = "Something went wrong. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/expense-tracker/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Income | ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f4f7f9; min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }
        .container { max-width: 500px; width: 100%; background: white; border-radius: 32px; padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.05); }
        h1 { font-size: 28px; margin-bottom: 24px; color: #0B2545; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #2c3e50; }
        input, select { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 16px; font-size: 16px; transition: 0.2s; }
        input:focus, select:focus { outline: none; border-color: #137A7F; box-shadow: 0 0 0 3px rgba(19,122,127,0.1); }
        button { width: 100%; padding: 14px; background: #137A7F; color: white; border: none; border-radius: 40px; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        button:hover { background: #0b5f63; transform: translateY(-2px); }
        .error { color: #dc2626; background: #fee2e2; padding: 12px; border-radius: 16px; margin-bottom: 20px; }
        .back-link { display: inline-block; margin-top: 20px; color: #137A7F; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .info-note { background: #eef2f6; padding: 8px 12px; border-radius: 12px; font-size: 12px; color: #5F6C7D; margin-top: 16px; text-align: center; }
    </style>
</head>
<body>
<div class="container">
    <h1>➕ Add Income</h1>
    <?php if($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Title / Source</label>
            <input type="text" name="title" required placeholder="e.g., Salary, Freelance, Gift">
        </div>
        <div class="form-group">
            <label>Amount</label>
            <input type="number" step="0.01" name="amount" required placeholder="Enter amount">
        </div>
        <div class="form-group">
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
        </div>
        <button type="submit">Add Income</button>
    </form>
    <div class="info-note">
        ⏱️ Transaction time is automatically recorded.
    </div>
    <a href="<?= $base_url ?>/dashboard.php" class="back-link">← Back to Dashboard</a>
</div>
</body>
</html>