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

$stmt = $conn->prepare("SELECT * FROM income WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$income = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$income) {
    die("Income record not found or you don't have permission.");
}

$current_currency = strtoupper(trim($income['currency'] ?? 'PKR'));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST['title']);
    $amount_pkr = (float)$_POST['amount_pkr'];
    $currency = $_POST['currency'];
    $client_time = $_POST['client_local_time'] ?? null;

    $update = $conn->prepare("UPDATE income SET title = ?, amount_pkr = ?, currency = ? WHERE id = ? AND user_id = ?");
    $update->execute([$title, $amount_pkr, $currency, $id, $user_id]);

    logAction($conn, $user_id, 'edit_income', "Updated income ID: $id", $client_time);

    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Income</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="/assets/client-time.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .form-card { background: white; border-radius: 32px; padding: 2rem; max-width: 500px; width: 100%; box-shadow: 0 8px 20px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-bottom: 1.5rem; color: #0f172a; }
        label { display: block; margin-top: 1rem; font-weight: 500; color: #334155; }
        input, select { width: 100%; padding: 0.7rem; margin-top: 0.3rem; border: 1px solid #cbd5e1; border-radius: 20px; font-family: inherit; }
        button { margin-top: 1.5rem; background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .cancel { background: #e2e8f0; color: #1e293b; margin-top: 0.5rem; text-align: center; display: block; text-decoration: none; padding: 0.7rem; border-radius: 40px; }
        .info-note { font-size: 12px; color: #6b7280; margin-top: 8px; text-align: center; }
    </style>
</head>
<body>
<div class="form-card">
    <h2>✏️ Edit Income</h2>
    <form method="POST" data-log>
        <label>Title</label>
        <input type="text" name="title" value="<?= htmlspecialchars($income['title']) ?>" required>

        <label>Amount</label>
        <input type="number" step="0.01" name="amount_pkr" value="<?= $income['amount_pkr'] ?>" required>

        <label>Currency</label>
        <select name="currency" required>
            <optgroup label="Major Currencies">
                <option value="USD" <?= $current_currency == 'USD' ? 'selected' : '' ?>>🇺🇸 US Dollar (USD)</option>
                <option value="EUR" <?= $current_currency == 'EUR' ? 'selected' : '' ?>>🇪🇺 Euro (EUR)</option>
                <option value="GBP" <?= $current_currency == 'GBP' ? 'selected' : '' ?>>🇬🇧 British Pound (GBP)</option>
                <option value="PKR" <?= $current_currency == 'PKR' ? 'selected' : '' ?>>🇵🇰 Pakistani Rupee (PKR)</option>
                <option value="INR" <?= $current_currency == 'INR' ? 'selected' : '' ?>>🇮🇳 Indian Rupee (INR)</option>
                <option value="AED" <?= $current_currency == 'AED' ? 'selected' : '' ?>>🇦🇪 UAE Dirham (AED)</option>
                <option value="SAR" <?= $current_currency == 'SAR' ? 'selected' : '' ?>>🇸🇦 Saudi Riyal (SAR)</option>
                <option value="KRW" <?= $current_currency == 'KRW' ? 'selected' : '' ?>>🇰🇷 South Korean Won (KRW)</option>
                <option value="JPY" <?= $current_currency == 'JPY' ? 'selected' : '' ?>>🇯🇵 Japanese Yen (JPY)</option>
                <option value="CNY" <?= $current_currency == 'CNY' ? 'selected' : '' ?>>🇨🇳 Chinese Yuan (CNY)</option>
                <option value="CAD" <?= $current_currency == 'CAD' ? 'selected' : '' ?>>🇨🇦 Canadian Dollar (CAD)</option>
                <option value="AUD" <?= $current_currency == 'AUD' ? 'selected' : '' ?>>🇦🇺 Australian Dollar (AUD)</option>
                <option value="CHF" <?= $current_currency == 'CHF' ? 'selected' : '' ?>>🇨🇭 Swiss Franc (CHF)</option>
                <option value="NZD" <?= $current_currency == 'NZD' ? 'selected' : '' ?>>🇳🇿 New Zealand Dollar (NZD)</option>
                <option value="SGD" <?= $current_currency == 'SGD' ? 'selected' : '' ?>>🇸🇬 Singapore Dollar (SGD)</option>
                <option value="MYR" <?= $current_currency == 'MYR' ? 'selected' : '' ?>>🇲🇾 Malaysian Ringgit (MYR)</option>
                <option value="THB" <?= $current_currency == 'THB' ? 'selected' : '' ?>>🇹🇭 Thai Baht (THB)</option>
                <option value="VND" <?= $current_currency == 'VND' ? 'selected' : '' ?>>🇻🇳 Vietnamese Dong (VND)</option>
                <option value="PHP" <?= $current_currency == 'PHP' ? 'selected' : '' ?>>🇵🇭 Philippine Peso (PHP)</option>
                <option value="IDR" <?= $current_currency == 'IDR' ? 'selected' : '' ?>>🇮🇩 Indonesian Rupiah (IDR)</option>
                <option value="BDT" <?= $current_currency == 'BDT' ? 'selected' : '' ?>>🇧🇩 Bangladeshi Taka (BDT)</option>
                <option value="LKR" <?= $current_currency == 'LKR' ? 'selected' : '' ?>>🇱🇰 Sri Lankan Rupee (LKR)</option>
                <option value="NPR" <?= $current_currency == 'NPR' ? 'selected' : '' ?>>🇳🇵 Nepalese Rupee (NPR)</option>
                <option value="AFN" <?= $current_currency == 'AFN' ? 'selected' : '' ?>>🇦🇫 Afghan Afghani (AFN)</option>
            </optgroup>
            <optgroup label="Other Major Currencies">
                <option value="TRY" <?= $current_currency == 'TRY' ? 'selected' : '' ?>>🇹🇷 Turkish Lira (TRY)</option>
                <option value="RUB" <?= $current_currency == 'RUB' ? 'selected' : '' ?>>🇷🇺 Russian Ruble (RUB)</option>
                <option value="BRL" <?= $current_currency == 'BRL' ? 'selected' : '' ?>>🇧🇷 Brazilian Real (BRL)</option>
                <option value="ZAR" <?= $current_currency == 'ZAR' ? 'selected' : '' ?>>🇿🇦 South African Rand (ZAR)</option>
                <option value="MXN" <?= $current_currency == 'MXN' ? 'selected' : '' ?>>🇲🇽 Mexican Peso (MXN)</option>
                <option value="SEK" <?= $current_currency == 'SEK' ? 'selected' : '' ?>>🇸🇪 Swedish Krona (SEK)</option>
                <option value="NOK" <?= $current_currency == 'NOK' ? 'selected' : '' ?>>🇳🇴 Norwegian Krone (NOK)</option>
                <option value="DKK" <?= $current_currency == 'DKK' ? 'selected' : '' ?>>🇩🇰 Danish Krone (DKK)</option>
                <option value="PLN" <?= $current_currency == 'PLN' ? 'selected' : '' ?>>🇵🇱 Polish Zloty (PLN)</option>
                <option value="HKD" <?= $current_currency == 'HKD' ? 'selected' : '' ?>>🇭🇰 Hong Kong Dollar (HKD)</option>
                <option value="ILS" <?= $current_currency == 'ILS' ? 'selected' : '' ?>>🇮🇱 Israeli Shekel (ILS)</option>
                <option value="KWD" <?= $current_currency == 'KWD' ? 'selected' : '' ?>>🇰🇼 Kuwaiti Dinar (KWD)</option>
                <option value="BHD" <?= $current_currency == 'BHD' ? 'selected' : '' ?>>🇧🇭 Bahraini Dinar (BHD)</option>
                <option value="OMR" <?= $current_currency == 'OMR' ? 'selected' : '' ?>>🇴🇲 Omani Rial (OMR)</option>
                <option value="QAR" <?= $current_currency == 'QAR' ? 'selected' : '' ?>>🇶🇦 Qatari Riyal (QAR)</option>
                <option value="EGP" <?= $current_currency == 'EGP' ? 'selected' : '' ?>>🇪🇬 Egyptian Pound (EGP)</option>
            </optgroup>
        </select>

        <div class="info-note">⏱️ Transaction date remains unchanged (original timestamp preserved).</div>
        <button type="submit">Update Income</button>
        <a href="dashboard.php" class="cancel">Cancel</a>
    </form>
</div>
</body>
</html>