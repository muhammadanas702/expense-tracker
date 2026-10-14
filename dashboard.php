<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/CurrencyConverter.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // The account no longer exists (for example it was deleted by an admin).
    session_unset();
    session_destroy();
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$is_admin = (bool) $user['is_admin'];

// Repeating (monthly) items that became due are added now.
require_once __DIR__ . '/modules/personal/lib.php';
personal_run_recurring($conn, $user_id);

/* ---------- FILTER LOGIC (everything from the URL is validated) ---------- */
$month = request_month();
$year  = request_year();
$from_date = valid_date($_GET['from_date'] ?? '');
$to_date   = valid_date($_GET['to_date'] ?? '');
$use_date_range = ($from_date !== null && $to_date !== null);

if ($use_date_range && $from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}
$from_date = $from_date ?? '';
$to_date   = $to_date ?? '';

if ($use_date_range) {
    $period_start = new DateTimeImmutable($from_date);
    $period_end   = (new DateTimeImmutable($to_date))->modify('+1 day'); // exclusive
} else {
    $period_start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $period_end   = $period_start->modify('+1 month');                   // exclusive
}
$days_in_period = max(1, (int) $period_start->diff($period_end)->days);
$range_from = $period_start->format('Y-m-d H:i:s');
$range_to   = $period_end->format('Y-m-d H:i:s');

/* ---------- DISPLAY CURRENCY ---------- */
$year_month = sprintf('%04d-%02d', $year, $month);
$base_currency = null;      // explicitly chosen for this month (if any)
$display_currency = 'PKR';

if ($use_date_range) {
    $display_currency = 'USD';
} else {
    $stmt = $conn->prepare("SELECT base_currency FROM user_monthly_currency WHERE user_id = ? AND `year_month` = ?");
    $stmt->execute([$user_id, $year_month]);
    $base_currency = normalize_currency($stmt->fetchColumn() ?: '');

    if ($base_currency !== null) {
        $display_currency = $base_currency;
    } else {
        // No base currency picked for this month yet: use the one picked most recently, else PKR.
        $stmt = $conn->prepare("SELECT base_currency FROM user_monthly_currency WHERE user_id = ? ORDER BY `year_month` DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $display_currency = normalize_currency($stmt->fetchColumn() ?: '') ?? 'PKR';
    }
}

// Every amount is converted PKR -> display currency (rows keep the rate they were saved with).
$toDisplay = static function (array $row) use ($display_currency): float {
    return CurrencyConverter::convert(CurrencyConverter::expenseInPkr($row), 'PKR', $display_currency);
};

$total_income = 0.0;
$total_expense = 0.0;
$trend = 0.0;
$categories = [];
$categoryTotals = [];
$top_category = ['category' => 'N/A', 'total' => 0];

$stmtInc = $conn->prepare("SELECT amount_pkr FROM income WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ?");
$stmtInc->execute([$user_id, $range_from, $range_to]);
foreach ($stmtInc->fetchAll(PDO::FETCH_ASSOC) as $inc) {
    $total_income += CurrencyConverter::convert($inc['amount_pkr'] ?? 0, 'PKR', $display_currency);
}

$stmtExp = $conn->prepare("SELECT category, amount, currency, amount_pkr FROM expenses WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ?");
$stmtExp->execute([$user_id, $range_from, $range_to]);
$categoryMap = [];
foreach ($stmtExp->fetchAll(PDO::FETCH_ASSOC) as $exp) {
    $value = $toDisplay($exp);
    $total_expense += $value;
    $cat = trim((string) ($exp['category'] ?? '')) !== '' ? (string) $exp['category'] : 'Uncategorized';
    $categoryMap[$cat] = ($categoryMap[$cat] ?? 0) + $value;
}

$total_income  = round($total_income, 2);
$total_expense = round($total_expense, 2);
$balance = round($total_income - $total_expense, 2);
$avgDailySpend = ($total_expense > 0) ? round($total_expense / $days_in_period, 2) : 0;
$savings_percent = ($total_income > 0) ? ($balance / $total_income) * 100 : 0;

// Spending trend compares with the previous calendar month (month view only).
if (!$use_date_range) {
    $prev_start = $period_start->modify('-1 month');
    $stmtPrev = $conn->prepare("SELECT amount, currency, amount_pkr FROM expenses WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ?");
    $stmtPrev->execute([$user_id, $prev_start->format('Y-m-d H:i:s'), $range_from]);
    $prev_expense = 0.0;
    foreach ($stmtPrev->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $prev_expense += $toDisplay($row);
    }
    $trend = ($prev_expense > 0) ? (($total_expense - $prev_expense) / $prev_expense) * 100 : 0;
}

// Category breakdown for the selected period, biggest first.
arsort($categoryMap);
foreach ($categoryMap as $name => $value) {
    $categories[] = (string) $name;
    $categoryTotals[] = round($value, 2);
}
if ($categories) {
    $top_category = ['category' => $categories[0], 'total' => $categoryTotals[0]];
}

/* ---------- RECENT TRANSACTIONS (latest five, all time) ---------- */
$incomeColumns = schema_has($conn, 'income', 'currency') ? 'amount, currency' : "amount_pkr AS amount, 'PKR' AS currency";
$stmt = $conn->prepare("SELECT id, title, $incomeColumns, transaction_date FROM income WHERE user_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 5");
$stmt->execute([$user_id]);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT id, title, amount, currency, category, transaction_date FROM expenses WHERE user_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 5");
$stmt->execute([$user_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrf = csrf_token();
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>ExpenseFlow | Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="<?= e($base_url) ?>/assets/client-time.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #f8fafc 0%, #f0f4f8 100%);
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100%;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(0,0,0,0.05);
            z-index: 1000;
            transition: left 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            padding: 20px 0;
            overflow-y: auto;
            box-shadow: 2px 0 20px rgba(0,0,0,0.05);
        }
        .sidebar.open {
            left: 0;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { left: -280px; opacity: 0; }
            to { left: 0; opacity: 1; }
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px 20px 20px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .sidebar-logo-img {
            height: 40px;
            width: auto;
            border-radius: 12px;
            transition: transform 0.2s;
        }
        .sidebar-logo-img:hover {
            transform: scale(1.05);
        }
        .sidebar-logo-text {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #0f766e, #1d4ed8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            margin: 4px 12px;
            border-radius: 16px;
            text-decoration: none;
            color: #1e293b;
            font-weight: 500;
            transition: all 0.25s ease;
        }
        .sidebar a:hover {
            background: #e2e8f0;
            transform: translateX(8px) scale(1.02);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(2px);
            z-index: 999;
            display: none;
        }
        .sidebar-overlay.active {
            display: block;
            animation: overlayFade 0.2s ease;
        }
        @keyframes overlayFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .menu-toggle {
            position: fixed;
            top: 16px;
            left: 16px;
            background: #0f766e;
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }
        .menu-toggle:active { transform: scale(0.94); }
        .menu-toggle:hover { background: #0d5c55; transform: scale(1.02); }
        .main {
            flex: 1;
            padding: 80px 20px 40px;
            transition: margin-left 0.2s;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .welcome {
            flex: 1;
        }
        .welcome h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #0f766e, #1d4ed8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.3px;
            animation: slideRight 0.5s ease;
        }
        @keyframes slideRight {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .welcome p {
            color: #475569;
            margin-top: 4px;
            font-size: 0.9rem;
            animation: fadeInUp 0.5s ease 0.1s both;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-group {
            display: flex;
            gap: 12px;
        }
        .profile-btn, .admin-btn {
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .profile-btn {
            background: linear-gradient(135deg, #4a5568, #2d3748);
            color: white;
        }
        .admin-btn {
            background: linear-gradient(135deg, #0f766e, #1d4ed8);
            color: white;
        }
        .profile-btn:hover, .admin-btn:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }
        .change-base-container {
            margin-top: 12px;
        }
        .change-base-btn {
            background: linear-gradient(135deg, #f97316, #ea580c);
            border: none;
            padding: 6px 16px;
            border-radius: 40px;
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: 0.2s;
        }
        .change-base-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(249,115,22,0.3);
        }
        .change-base-form {
            display: none;
            margin-top: 12px;
            background: white;
            border-radius: 28px;
            padding: 12px 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
        }
        .change-base-form select {
            padding: 6px 12px;
            border-radius: 40px;
            border: 1px solid #cbd5e1;
            margin: 0 8px;
        }
        .change-base-form button {
            padding: 5px 14px;
            border-radius: 40px;
            border: none;
            cursor: pointer;
            font-weight: 500;
        }
        .change-base-form button[type="submit"] {
            background: #0f766e;
            color: white;
        }
        .change-base-form button[type="button"] {
            background: #e2e8f0;
            color: #1e293b;
        }
        .success-message, .error-message {
            padding: 12px;
            border-radius: 28px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success-message {
            background: #d1fae5;
            color: #065f46;
        }
        .error-message {
            background: #fee2e2;
            color: #b91c1c;
        }
        .filter-form {
            background: white;
            border-radius: 32px;
            padding: 16px 24px;
            margin-bottom: 28px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.2s;
        }
        .filter-form:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        }
        .date-range {
            display: flex;
            gap: 16px;
        }
        .field {
            display: flex;
            flex-direction: column;
        }
        .field label {
            font-size: 12px;
            font-weight: 600;
            color: #5F6C7D;
            margin-bottom: 4px;
        }
        .field input, .field select {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-family: inherit;
            background: white;
            transition: 0.2s;
        }
        .field input:focus, .field select:focus {
            border-color: #0f766e;
            outline: none;
            box-shadow: 0 0 0 3px rgba(15,118,110,0.1);
        }
        .apply-btn, .clear-btn {
            padding: 8px 24px;
            border-radius: 40px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .apply-btn {
            background: #0f766e;
            color: white;
            box-shadow: 0 2px 4px rgba(15,118,110,0.2);
        }
        .apply-btn:hover {
            background: #0d5c55;
            transform: translateY(-2px);
        }
        .clear-btn {
            background: #e2e8f0;
            color: #1e293b;
            text-decoration: none;
            display: inline-block;
        }
        .clear-btn:hover {
            background: #cbd5e1;
            transform: translateY(-2px);
        }
        .quick-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 32px;
        }
        .pill {
            background: white;
            padding: 8px 18px;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #0f172a;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s;
            animation: fadeInUp 0.4s ease-out backwards;
        }
        .pill:nth-child(1) { animation-delay: 0.1s; }
        .pill:nth-child(2) { animation-delay: 0.2s; }
        .pill:nth-child(3) { animation-delay: 0.3s; }
        .pill:hover {
            transform: translateY(-2px) scale(1.02);
            background: #e2e8f0;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        .card {
            background: white;
            border-radius: 28px;
            padding: 24px 20px;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border: 1px solid rgba(0,0,0,0.03);
            animation: fadeInUp 0.5s ease-out backwards;
        }
        .card:nth-child(1) { animation-delay: 0.1s; }
        .card:nth-child(2) { animation-delay: 0.2s; }
        .card:nth-child(3) { animation-delay: 0.3s; }
        .card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15);
            border-color: rgba(15,118,110,0.2);
        }
        .card h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #5F6C7D;
            margin-bottom: 12px;
        }
        .card p {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .income p { color: #10b981; }
        .expense p { color: #f97316; }
        .balance p { color: #0f766e; }
        .reset-section {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 24px 0 32px;
        }
        .danger-btn {
            background: #fee2e2;
            color: #dc2626;
            padding: 8px 18px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .danger-btn:hover {
            background: #fecaca;
            transform: translateY(-2px) scale(1.02);
        }
        .section-title {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 36px 0 20px;
            letter-spacing: -0.3px;
            position: relative;
            display: inline-block;
        }
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 0;
            width: 50%;
            height: 3px;
            background: linear-gradient(90deg, #0f766e, #1d4ed8);
            border-radius: 3px;
        }
        .transaction {
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid rgba(0,0,0,0.02);
            animation: fadeInUp 0.4s ease-out backwards;
        }
        .transaction:hover {
            transform: translateX(8px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            background: #f8fafc;
        }
        .income-item { border-left: 5px solid #10b981; }
        .expense-item { border-left: 5px solid #f97316; }
        .transaction span {
            font-weight: 600;
            font-size: 1rem;
        }
        .transaction small {
            font-size: 0.7rem;
            color: #6b7280;
            display: block;
        }
        .transaction b {
            font-size: 1.1rem;
        }
        .income-item b { color: #10b981; }
        .expense-item b { color: #f97316; }
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin: 20px 0 40px;
        }
        .chart-box {
            background: white;
            padding: 20px;
            border-radius: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            transition: all 0.3s;
            cursor: pointer;
            animation: fadeInUp 0.5s ease-out backwards;
        }
        .chart-box:nth-child(1) { animation-delay: 0.1s; }
        .chart-box:nth-child(2) { animation-delay: 0.2s; }
        .chart-box:nth-child(3) { animation-delay: 0.3s; }
        .chart-box:hover {
            transform: scale(1.02);
            box-shadow: 0 12px 28px -12px rgba(0,0,0,0.15);
        }
        canvas {
            max-height: 260px;
            width: 100%;
        }
        @media (min-width: 768px) {
            body { flex-direction: row; }
            .menu-toggle, .sidebar-overlay { display: none; }
            .sidebar {
                position: sticky;
                left: 0;
                width: 280px;
                height: 100vh;
                display: flex !important;
                flex-direction: column;
                background: rgba(255,255,255,0.96);
                border-right: 1px solid #e2e8f0;
            }
            .main {
                padding: 40px 32px;
                margin-top: 0;
            }
            .top-bar { flex-direction: row; }
            .filter-form { flex-wrap: wrap; }
        }
        @media (max-width: 768px) {
            .main { padding-top: 70px; }
            .top-bar { flex-direction: column; align-items: stretch; }
            .btn-group { justify-content: center; }
            .date-range { flex-direction: column; width: 100%; }
            .filter-form { flex-direction: column; align-items: stretch; }
            .cards { grid-template-columns: 1fr; }
            .transaction { flex-direction: column; align-items: flex-start; }
            .transaction div:last-child { align-self: flex-end; }
            .charts-container { grid-template-columns: 1fr; }
            .change-base-form select { margin: 8px 0; width: 100%; }
            .change-base-form button { margin-top: 6px; width: 100%; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <img class="sidebar-logo-img" src="<?= e($base_url) ?>/ExpenseFlow Logo.png" alt="ExpenseFlow Logo">
        <div class="sidebar-logo-text">Expense<span>Flow</span></div>
    </div>
    <a href="#">📊 Dashboard</a>
    <a href="<?= e($base_url) ?>/income/add-income.php">➕ Add Income</a>
    <a href="<?= e($base_url) ?>/expense/add-expense.php">➖ Add Expense</a>
    <a href="<?= e($base_url) ?>/reports/view-report.php">📄 Full Report</a>
    <a href="<?= e($base_url) ?>/auth/logout.php" data-post data-csrf="<?= e($csrf) ?>">🚪 Logout</a>
    <a href="<?= e($base_url) ?>/auth/delete-account.php" style="color:#ef4444;">🗑 Delete Account</a>
</div>

<div class="main">
    <?php if(isset($_SESSION['success'])): ?>
    <div id="flashMessage" class="success-message"><?= e($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if(isset($_SESSION['error'])): ?>
    <div id="flashMessage" class="error-message"><?= e($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

    <div class="top-bar">
        <div class="welcome">
            <h1>Welcome back, <?= e($_SESSION["user_name"] ?? '') ?> 👋</h1>
            <p><?= e($use_date_range ? "Custom range: $from_date to $to_date (converted to USD)" : $period_start->format('F Y') . " (base: $display_currency)") ?></p>
            <?php if (!$use_date_range): ?>
                <div class="change-base-container">
                    <button type="button" id="changeBaseBtn" class="change-base-btn">🔄 Change Base Currency</button>
                    <div id="changeBaseForm" class="change-base-form">
                        <form method="POST" action="change-base-currency.php" id="baseCurrencyForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="year_month" value="<?= e($year_month) ?>">
                            <label for="newBaseCurrency" style="font-size: 0.85rem;">New Base Currency:</label>
                            <select name="new_base_currency" id="newBaseCurrency" required>
                                <?= currency_select_options($display_currency) ?>
                            </select>
                            <button type="submit">Change</button>
                            <button type="button" onclick="document.getElementById('changeBaseForm').style.display='none'">Cancel</button>
                        </form>
                    </div>
                </div>
                <script>
                    document.getElementById('changeBaseBtn').addEventListener('click', function() {
                        var formDiv = document.getElementById('changeBaseForm');
                        formDiv.style.display = (window.getComputedStyle(formDiv).display === 'none') ? 'block' : 'none';
                    });
                </script>
            <?php endif; ?>
        </div>
        <div class="btn-group">
            <a href="<?= e($base_url) ?>/profile.php" class="profile-btn">👤 Profile</a>
            <?php if ($is_admin): ?>
                <a href="<?= e($base_url) ?>/admin/index.php" class="admin-btn">👑 Admin Panel</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="GET" class="filter-form">
        <div class="date-range">
            <div class="field"><label>From</label><input type="date" name="from_date" value="<?= e($from_date) ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to_date" value="<?= e($to_date) ?>"></div>
        </div>
        <button type="submit" class="apply-btn">Apply</button>
        <?php if($use_date_range || !empty($_GET)): ?>
            <a href="<?= e($base_url) ?>/dashboard.php" class="clear-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="quick-stats">
        <div class="pill">📅 Avg daily spend: <?= number_format($avgDailySpend,2) ?> <?= e($display_currency) ?></div>
        <div class="pill">🎯 Savings goal: <?= round($savings_percent,1) ?>% of income</div>
        <div class="pill">💡 Tip: <?= ($savings_percent < 15) ? "Try reducing dining out 🍔" : "Great saving habit! 🚀" ?></div>
    </div>

    <div class="cards">
        <div class="card income"><h3>💰 Total Income</h3><p><?= $total_income == 0 ? '0.00' : number_format($total_income,2) . ' ' . e($display_currency) ?></p></div>
        <div class="card expense"><h3>💸 Total Expense</h3><p><?= $total_expense == 0 ? '0.00' : number_format($total_expense,2) . ' ' . e($display_currency) ?></p></div>
        <div class="card balance"><h3>⚖️ Net Balance</h3><p><?= $balance == 0 ? '0.00' : number_format($balance,2) . ' ' . e($display_currency) ?></p></div>
    </div>

    <div class="reset-section">
        <a href="<?= e($base_url) ?>/reset-data.php" class="danger-btn" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"type":"income"}' onclick="return confirm('Reset all income?')">⟳ Reset Income</a>
        <a href="<?= e($base_url) ?>/reset-data.php" class="danger-btn" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"type":"expense"}' onclick="return confirm('Reset all expenses?')">⟳ Reset Expenses</a>
        <a href="<?= e($base_url) ?>/reset-data.php" class="danger-btn" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"type":"all"}' onclick="return confirm('⚠️ Delete all financial data?')">⚠️ Reset All</a>
    </div>

    <h2 class="section-title">🧠 Smart Insights</h2>
    <div class="cards">
        <div class="card"><h3>📈 Financial Health</h3><p><?= $savings_percent>=30?"Excellent 🔥":($savings_percent>=15?"Good 🙂":"Needs Control ⚠️") ?></p><small><?=round($savings_percent,1)?>% savings rate</small></div>
        <div class="card"><h3>📉 Spending Trend</h3><p><?= (!$use_date_range && $trend!=0) ? ($trend>0?"↑ +".round($trend,1):"↓ ".round(abs($trend),1))."%" : "N/A" ?></p><small><?= $use_date_range ? "Custom range" : "vs last month" ?></small></div>
        <div class="card"><h3>🏷️ Top Category</h3><p><?= e($top_category['category'] ?? 'N/A') ?></p><small>highest expense area</small></div>
        <div class="card"><h3>📌 Monthly Status</h3><p><?= $balance>0?"Surplus 🟢":($balance<0?"Deficit 🔴":"Neutral ⚪") ?></p><small>income vs expense</small></div>
    </div>

    <h2 class="section-title">💵 Recent Income</h2>
    <?php if ($incomes): foreach ($incomes as $inc): ?>
        <div class="transaction income-item">
            <div><span>➕ <?= e($inc['title']) ?></span><small><?= e(date('d M Y', strtotime($inc['transaction_date']))) ?></small></div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <b>+ <?= number_format((float) $inc['amount'], 2) ?> <?= e($inc['currency']) ?></b>
                <a href="<?= e($base_url) ?>/edit-income.php?id=<?= (int) $inc['id'] ?>" style="color:#0f766e; text-decoration:none;" aria-label="Edit income">✏️</a>
                <a href="<?= e($base_url) ?>/delete-income.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"id":<?= (int) $inc['id'] ?>}' onclick="return confirm('Delete this income?')" style="color:#ef4444; text-decoration:none;" aria-label="Delete income">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No income records yet.</p><?php endif; ?>

    <h2 class="section-title">💸 Recent Expenses</h2>
    <?php if ($expenses): foreach ($expenses as $exp): ?>
        <div class="transaction expense-item">
            <div><span>➖ <?= e($exp['title']) ?> (<?= e($exp['category']) ?>)</span><small><?= e(date('d M Y', strtotime($exp['transaction_date']))) ?></small></div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <b>- <?= number_format((float) $exp['amount'], 2) ?> <?= e($exp['currency']) ?></b>
                <a href="<?= e($base_url) ?>/edit-expense.php?id=<?= (int) $exp['id'] ?>" style="color:#0f766e; text-decoration:none;" aria-label="Edit expense">✏️</a>
                <a href="<?= e($base_url) ?>/delete-expense.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"id":<?= (int) $exp['id'] ?>}' onclick="return confirm('Delete this expense?')" style="color:#ef4444; text-decoration:none;" aria-label="Delete expense">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No expense records yet.</p><?php endif; ?>

    <h2 class="section-title">📊 Analytics Center (Click on any chart for details)</h2>
    <div class="charts-container">
        <div class="chart-box"><canvas id="doughnutChart"></canvas></div>
        <div class="chart-box"><canvas id="barChart"></canvas></div>
        <div class="chart-box"><canvas id="categoryPieChart"></canvas></div>
    </div>
</div>

<script>
    const totalIncome = <?= json_encode($total_income, $json_flags) ?>;
    const totalExpense = <?= json_encode($total_expense, $json_flags) ?>;
    const catLabels = <?= json_encode($categories, $json_flags) ?>;
    const catValues = <?= json_encode($categoryTotals, $json_flags) ?>;

    // Chart.js 4 replaced getElementsAtEvent() with getElementsAtEventForMode().
    function pointsAt(chart, event) {
        return chart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, true);
    }

    function showDetails(title, amount, percentage, extra = '') {
        alert(`${title}\nAmount: ${amount.toFixed(2)}\nPercentage: ${percentage.toFixed(1)}%${extra ? '\n' + extra : ''}`);
    }

    const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
    const doughnutChart = new Chart(doughnutCtx, {
        type: 'doughnut',
        data: { labels: ['Income','Expense'], datasets: [{ data: [totalIncome,totalExpense], backgroundColor: ['#10b981','#f97316'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: ${ctx.raw.toFixed(2)}` } }, legend: { labels: { color: '#1e293b' } } } }
    });
    document.getElementById('doughnutChart').addEventListener('click', (event) => {
        const activePoints = pointsAt(doughnutChart, event);
        if (activePoints.length) {
            const idx = activePoints[0].index;
            const label = doughnutChart.data.labels[idx];
            const value = doughnutChart.data.datasets[0].data[idx];
            const total = totalIncome + totalExpense;
            const pct = total > 0 ? (value / total) * 100 : 0;
            showDetails(label, value, pct);
        }
    });

    const barCtx = document.getElementById('barChart').getContext('2d');
    const barChart = new Chart(barCtx, {
        type: 'bar',
        data: { labels: ['Income','Expense'], datasets: [{ label: 'Amount', data: [totalIncome,totalExpense], backgroundColor: ['#10b981','#f97316'], borderRadius: 8 }] },
        options: { responsive: true, maintainAspectRatio: true, scales: { y: { ticks: { color: '#334155' } }, x: { ticks: { color: '#1e293b' } } }, plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label} ${ctx.raw.toFixed(2)}` } } } }
    });
    document.getElementById('barChart').addEventListener('click', (event) => {
        const active = pointsAt(barChart, event);
        if (active.length) {
            const idx = active[0].index;
            const label = barChart.data.labels[idx];
            const value = barChart.data.datasets[0].data[idx];
            const total = totalIncome + totalExpense;
            const pct = total > 0 ? (value / total) * 100 : 0;
            showDetails(label, value, pct);
        }
    });

    if (catLabels.length) {
        const pieChart = new Chart(document.getElementById('categoryPieChart'), {
            type: 'pie',
            data: { labels: catLabels, datasets: [{ data: catValues, backgroundColor: ['#137A7F','#0B2545','#4DA8DA','#F59E0B','#10b981','#a855f7','#ef4444'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw.toFixed(2)}` } }, legend: { position: 'bottom', labels: { color: '#1e293b' } } } }
        });
        document.getElementById('categoryPieChart').addEventListener('click', (event) => {
            const active = pointsAt(pieChart, event);
            if (active.length) {
                const idx = active[0].index;
                const label = pieChart.data.labels[idx];
                const value = pieChart.data.datasets[0].data[idx];
                const totalExp = catValues.reduce((a,b) => a + b, 0);
                const pct = totalExp > 0 ? (value / totalExp) * 100 : 0;
                showDetails(`Category: ${label}`, value, pct, `Out of total expenses: ${totalExp.toFixed(2)}`);
            }
        });
    } else {
        document.getElementById('categoryPieChart').parentElement.innerHTML = '<div class="chart-box" style="text-align:center; padding:2rem;">📭 No category data</div>';
    }

    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
    function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('active'); }
    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    if (window.innerWidth >= 768) closeSidebar();

    // Auto-hide flash message after 4 seconds
    var flashMsg = document.getElementById('flashMessage');
    if (flashMsg) {
        setTimeout(function() {
            flashMsg.style.transition = 'opacity 0.5s';
            flashMsg.style.opacity = '0';
            setTimeout(function() {
                if (flashMsg && flashMsg.remove) flashMsg.remove();
            }, 500);
        }, 4000);
    }
</script>
</body>
</html>