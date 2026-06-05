<?php
// Start session and include required files
session_start();
require_once "config/app.php";
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/CurrencyConverter.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$is_admin = (bool)$user['is_admin'];

/* ---------- FILTER LOGIC (unchanged) ---------- */
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$from_date = $_GET['from_date'] ?? '';
$to_date   = $_GET['to_date'] ?? '';
$use_date_range = (!empty($from_date) && !empty($to_date));

$total_income = 0;
$total_expense = 0;
$balance = 0;
$trend = 0;
$avgDailySpend = 0;
$display_currency = "PKR";
$base_currency = null;
$categories = [];
$categoryTotals = [];
$top_category = ['category' => 'N/A', 'total' => 0];

if ($use_date_range) {
    $display_currency = "USD";
    $date_condition = "transaction_date BETWEEN ? AND ?";
    $date_params = [$from_date, $to_date . ' 23:59:59'];

    $stmtInc = $conn->prepare("SELECT amount_pkr FROM income WHERE user_id = ? AND $date_condition");
    $stmtInc->execute(array_merge([$user_id], $date_params));
    $incomesRaw = $stmtInc->fetchAll();
    foreach ($incomesRaw as $inc) {
        $total_income += CurrencyConverter::convert($inc['amount_pkr'], 'PKR', 'USD');
    }

    $stmtExp = $conn->prepare("SELECT amount, currency FROM expenses WHERE user_id = ? AND $date_condition");
    $stmtExp->execute(array_merge([$user_id], $date_params));
    $expensesRaw = $stmtExp->fetchAll();
    foreach ($expensesRaw as $exp) {
        $currency = $exp['currency'] ?? 'PKR';
        $total_expense += CurrencyConverter::convert($exp['amount'], $currency, 'USD');
    }

    $balance = $total_income - $total_expense;
    $days = (strtotime($to_date) - strtotime($from_date)) / 86400 + 1;
    $avgDailySpend = ($total_expense > 0 && $days > 0) ? round($total_expense / $days, 2) : 0;
} else {
    $year_month = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
    $stmt = $conn->prepare("SELECT base_currency FROM user_monthly_currency WHERE user_id = ? AND `year_month` = ?");
    $stmt->execute([$user_id, $year_month]);
    $base_currency = $stmt->fetchColumn();

    $stmtExp = $conn->prepare("SELECT amount, currency FROM expenses WHERE user_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?");
    $stmtExp->execute([$user_id, $month, $year]);
    $expensesRaw = $stmtExp->fetchAll();

    if (!$base_currency) {
        $display_currency = "USD";
        foreach ($expensesRaw as $exp) {
            $total_expense += CurrencyConverter::convert($exp['amount'], $exp['currency'] ?? 'PKR', 'USD');
        }
        $balance = -$total_expense;
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $avgDailySpend = ($total_expense > 0) ? round($total_expense / $daysInMonth, 2) : 0;
    } else {
        $display_currency = $base_currency;

        $stmtInc = $conn->prepare("SELECT amount_pkr FROM income WHERE user_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?");
        $stmtInc->execute([$user_id, $month, $year]);
        $incomesRaw = $stmtInc->fetchAll();
        foreach ($incomesRaw as $inc) {
            $total_income += CurrencyConverter::convert($inc['amount_pkr'], 'PKR', $base_currency);
        }

        foreach ($expensesRaw as $exp) {
            $currency = $exp['currency'] ?? 'PKR';
            $total_expense += CurrencyConverter::convert($exp['amount'], $currency, $base_currency);
        }

        $balance = $total_income - $total_expense;

        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth == 0) {
            $prevMonth = 12;
            $prevYear--;
        }
        $prevStmt = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?");
        $prevStmt->execute([$user_id, $prevMonth, $prevYear]);
        $prev_expense = $prevStmt->fetch()['total'] ?? 0;
        $trend = ($prev_expense > 0) ? (($total_expense - $prev_expense) / $prev_expense) * 100 : 0;

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $avgDailySpend = ($total_expense > 0) ? round($total_expense / $daysInMonth, 2) : 0;
    }
}

$savings_percent = ($total_income > 0) ? ($balance / $total_income) * 100 : 0;

/* RECENT INCOME */
$stmt = $conn->prepare("SELECT id, title, amount_pkr, transaction_date FROM income WHERE user_id = ? ORDER BY transaction_date DESC LIMIT 5");
$stmt->execute([$user_id]);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* RECENT EXPENSES */
$stmt = $conn->prepare("SELECT id, title, amount, currency, category, transaction_date FROM expenses WHERE user_id = ? ORDER BY transaction_date DESC LIMIT 5");
$stmt->execute([$user_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Category totals */
$stmt = $conn->prepare("SELECT category, SUM(amount) as total FROM expenses WHERE user_id = ? GROUP BY category");
$stmt->execute([$user_id]);
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cats as $cat) {
    $categories[] = $cat['category'];
    $categoryTotals[] = (float)$cat['total'];
}
if (!empty($categoryTotals)) {
    $maxIdx = array_keys($categoryTotals, max($categoryTotals))[0];
    $top_category = ['category' => $categories[$maxIdx], 'total' => $categoryTotals[$maxIdx]];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>ExpenseFlow | Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/client-time.js"></script>
    <style>
        /* ---------- RESET & GLOBAL ---------- */
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

        /* ---------- SIDEBAR (MOBILE DRAWER) ---------- */
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

        /* ---------- MAIN CONTENT ---------- */
        .main {
            flex: 1;
            padding: 80px 20px 40px;
            transition: margin-left 0.2s;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Top bar */
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

        /* Filter form */
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

        /* Quick stats pills */
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

        /* Cards grid */
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

        /* Reset buttons */
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

        /* Section titles */
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

        /* Transaction rows */
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

        /* Charts */
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

        /* Responsive */
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
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <!-- YOUR LOGO IMAGE ADDED HERE -->
        <img class="sidebar-logo-img" src="<?= $base_url ?>/ExpenseFlow Logo.png" alt="ExpenseFlow Logo">
        <div class="sidebar-logo-text">Expense<span>Flow</span></div>
    </div>
    <a href="#">📊 Dashboard</a>
    <a href="<?= $base_url ?>/income/add-income.php">➕ Add Income</a>
    <a href="<?= $base_url ?>/expense/add-expense.php">➖ Add Expense</a>
    <a href="<?= $base_url ?>/reports/view-report.php">📄 Full Report</a>
    <a href="<?= $base_url ?>/auth/logout.php" data-log>🚪 Logout</a>
    <a href="<?= $base_url ?>/auth/delete-account.php" onclick="return confirm('Delete account permanently?')" style="color:#ef4444;">🗑 Delete Account</a>
</div>

<div class="main">
    <div class="top-bar">
        <div class="welcome">
            <h1>Welcome back, <?php echo htmlspecialchars($_SESSION["user_name"]); ?> 👋</h1>
            <p><?php echo $use_date_range ? "Custom range: $from_date to $to_date (converted to USD)" : date('F Y', mktime(0,0,0,$month,1,$year)) . " (base: $display_currency)"; ?></p>
        </div>
        <div class="btn-group">
            <a href="<?= $base_url ?>/profile.php" class="profile-btn">👤 Profile</a>
            <?php if ($is_admin): ?>
                <a href="<?= $base_url ?>/admin/index.php" class="admin-btn">👑 Admin Panel</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="GET" class="filter-form">
        <div class="date-range">
            <div class="field"><label>From</label><input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>"></div>
            <div class="field"><label>To</label><input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>"></div>
        </div>
        <button type="submit" class="apply-btn">Apply</button>
        <?php if($use_date_range || !empty($_GET)): ?>
            <a href="<?= $base_url ?>/dashboard.php" class="clear-btn">Clear</a>
        <?php endif; ?>
    </form>

    <div class="quick-stats">
        <div class="pill">📅 Avg daily spend: <?= number_format($avgDailySpend,2) ?> <?= $display_currency ?></div>
        <div class="pill">🎯 Savings goal: <?= round($savings_percent,1) ?>% of income</div>
        <div class="pill">💡 Tip: <?= ($savings_percent < 15) ? "Try reducing dining out 🍔" : "Great saving habit! 🚀" ?></div>
    </div>

    <div class="cards">
        <div class="card income"><h3>💰 Total Income</h3><p><?= number_format($total_income,2) ?> <?= $display_currency ?></p></div>
        <div class="card expense"><h3>💸 Total Expense</h3><p><?= number_format($total_expense,2) ?> <?= $display_currency ?></p></div>
        <div class="card balance"><h3>⚖️ Net Balance</h3><p><?= number_format($balance,2) ?> <?= $display_currency ?></p></div>
    </div>

    <div class="reset-section">
        <a href="<?= $base_url ?>/reset-data.php?type=income" class="danger-btn" onclick="return confirm('Reset all income?')">⟳ Reset Income</a>
        <a href="<?= $base_url ?>/reset-data.php?type=expense" class="danger-btn" onclick="return confirm('Reset all expenses?')">⟳ Reset Expenses</a>
        <a href="<?= $base_url ?>/reset-data.php?type=all" class="danger-btn" onclick="return confirm('⚠️ Delete all financial data?')">⚠️ Reset All</a>
    </div>

    <h2 class="section-title">🧠 AI Insights</h2>
    <div class="cards">
        <div class="card"><h3>📈 Financial Health</h3><p><?= $savings_percent>=30?"Excellent 🔥":($savings_percent>=15?"Good 🙂":"Needs Control ⚠️") ?></p><small><?=round($savings_percent,1)?>% savings rate</small></div>
        <div class="card"><h3>📉 Spending Trend</h3><p><?= (!$use_date_range && $trend!=0) ? ($trend>0?"↑ +".round($trend,1):"↓ ".round(abs($trend),1))."%" : "N/A" ?></p><small><?= $use_date_range ? "Custom range" : "vs last month" ?></small></div>
        <div class="card"><h3>🏷️ Top Category</h3><p><?= htmlspecialchars($top_category['category']??'N/A') ?></p><small>highest expense area</small></div>
        <div class="card"><h3>📌 Monthly Status</h3><p><?= $balance>0?"Surplus 🟢":($balance<0?"Deficit 🔴":"Neutral ⚪") ?></p><small>income vs expense</small></div>
    </div>

    <h2 class="section-title">💵 Recent Income</h2>
    <?php if($incomes): foreach($incomes as $inc): ?>
        <div class="transaction income-item">
            <div><span>➕ <?=htmlspecialchars($inc['title'])?></span><small><?=date('d M Y', strtotime($inc['transaction_date']))?></small></div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <b>+ <?=number_format($inc['amount_pkr'],2)?> PKR</b>
                <a href="<?= $base_url ?>/edit-income.php?id=<?=$inc['id']?>" data-log style="color:#0f766e; text-decoration:none;">✏️</a>
                <a href="<?= $base_url ?>/delete-income.php?id=<?=$inc['id']?>" data-log onclick="return confirm('Delete this income?')" style="color:#ef4444; text-decoration:none;">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No income records for selected period.</p><?php endif; ?>

    <h2 class="section-title">💸 Recent Expenses</h2>
    <?php if($expenses): foreach($expenses as $exp): ?>
        <div class="transaction expense-item">
            <div><span>➖ <?=htmlspecialchars($exp['title'])?> (<?=htmlspecialchars($exp['category'])?>)</span><small><?=date('d M Y', strtotime($exp['transaction_date']))?></small></div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <b>- <?=number_format($exp['amount'],2)?> <?=htmlspecialchars($exp['currency'])?></b>
                <a href="<?= $base_url ?>/edit-expense.php?id=<?=$exp['id']?>" data-log style="color:#0f766e; text-decoration:none;">✏️</a>
                <a href="<?= $base_url ?>/delete-expense.php?id=<?=$exp['id']?>" data-log onclick="return confirm('Delete this expense?')" style="color:#ef4444; text-decoration:none;">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No expense records for selected period.</p><?php endif; ?>

    <h2 class="section-title">📊 Analytics Center (Click on any chart for details)</h2>
    <div class="charts-container">
        <div class="chart-box"><canvas id="doughnutChart"></canvas></div>
        <div class="chart-box"><canvas id="barChart"></canvas></div>
        <div class="chart-box"><canvas id="categoryPieChart"></canvas></div>
    </div>
</div>

<script>
    const totalIncome = <?= json_encode($total_income) ?>;
    const totalExpense = <?= json_encode($total_expense) ?>;
    const catLabels = <?= json_encode($categories) ?>;
    const catValues = <?= json_encode($categoryTotals) ?>;

    function showDetails(title, amount, percentage, extra = '') {
        alert(`${title}\nAmount: <?= $display_currency ?> ${amount.toFixed(2)}\nPercentage: ${percentage.toFixed(1)}%${extra ? '\n' + extra : ''}`);
    }

    const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
    const doughnutChart = new Chart(doughnutCtx, {
        type: 'doughnut',
        data: { labels: ['Income','Expense'], datasets: [{ data: [totalIncome,totalExpense], backgroundColor: ['#10b981','#f97316'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.label}: <?= $display_currency ?> ${ctx.raw.toFixed(2)}` } }, legend: { labels: { color: '#1e293b' } } } }
    });
    document.getElementById('doughnutChart').addEventListener('click', (event) => {
        const activePoints = doughnutChart.getElementsAtEvent(event);
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
        data: { labels: ['Income','Expense'], datasets: [{ label: '<?= $display_currency ?>', data: [totalIncome,totalExpense], backgroundColor: ['#10b981','#f97316'], borderRadius: 8 }] },
        options: { responsive: true, maintainAspectRatio: true, scales: { y: { ticks: { color: '#334155' } }, x: { ticks: { color: '#1e293b' } } }, plugins: { tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label} ${ctx.raw.toFixed(2)}` } } } }
    });
    document.getElementById('barChart').addEventListener('click', (event) => {
        const active = barChart.getElementsAtEvent(event);
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
            options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.label}: <?= $display_currency ?> ${ctx.raw.toFixed(2)}` } }, legend: { position: 'bottom', labels: { color: '#1e293b' } } } }
        });
        document.getElementById('categoryPieChart').addEventListener('click', (event) => {
            const active = pieChart.getElementsAtEvent(event);
            if (active.length) {
                const idx = active[0].index;
                const label = pieChart.data.labels[idx];
                const value = pieChart.data.datasets[0].data[idx];
                const totalExp = catValues.reduce((a,b) => a + b, 0);
                const pct = totalExp > 0 ? (value / totalExp) * 100 : 0;
                showDetails(`Category: ${label}`, value, pct, `Out of total expenses: <?= $display_currency ?> ${totalExp.toFixed(2)}`);
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
</script>
</body>
</html>