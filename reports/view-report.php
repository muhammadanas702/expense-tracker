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

// Fetch user details
$stmtUser = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);

$from_date = $_GET['from_date'] ?? date('Y-01-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Handle Excel export with styling
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"expense_report_" . date('Y-m-d') . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    echo '<html>
    <head>
        <meta charset="UTF-8">
        <title>ExpenseFlow Report</title>
        <style>
            body { font-family: "Segoe UI", Arial, sans-serif; margin: 20px; }
            h2 { color: #0B2545; border-bottom: 2px solid #137A7F; padding-bottom: 8px; }
            .header { margin-bottom: 30px; }
            .header h1 { color: #137A7F; }
            .user-info { background: #f0f7f8; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
            table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
            th { background: #137A7F; color: white; padding: 10px; text-align: left; }
            td { padding: 8px 10px; border-bottom: 1px solid #ddd; }
            .summary-table th { background: #0B2545; }
            .income-row { background-color: #e6f7e6; }
            .expense-row { background-color: #ffe6e6; }
            .total-row { font-weight: bold; background: #f0f0f0; }
            .ai-box { background: #fef9e3; padding: 12px; border-left: 4px solid #137A7F; margin: 20px 0; }
        </style>
    </head>
    <body>';
    
    echo '<div class="header">';
    echo '<h1>📊 ExpenseFlow Financial Report</h1>';
    echo '<div class="user-info"><strong>' . htmlspecialchars($user['name']) . '</strong> | ' . htmlspecialchars($user['email']) . '</div>';
    echo "<p>Period: " . htmlspecialchars($from_date) . " to " . htmlspecialchars($to_date) . "</p>";
    echo '</div>';
    
    // Summary
    $stmtIncTotal = $conn->prepare("SELECT SUM(amount_pkr) as total FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmtIncTotal->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $total_income = $stmtIncTotal->fetch()['total'] ?? 0;
    
    $stmtExpTotal = $conn->prepare("SELECT amount, currency FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmtExpTotal->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $expensesAll = $stmtExpTotal->fetchAll();
    $total_expense = 0;
    foreach ($expensesAll as $exp) {
        $total_expense += CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
    }
    $total_savings = $total_income - $total_expense;
    $savings_percent = ($total_income > 0) ? ($total_savings / $total_income) * 100 : 0;
    
    echo '<h2>💰 Summary</h2>';
    echo '<table class="summary-table">';
    echo '<tr><th>Metric</th><th>Amount (PKR)</th><tr>';
    echo '缘<td>Total Income</td><td>' . number_format($total_income, 2) . '</td></tr>';
    echo '缘<td>Total Expense</td><td>' . number_format($total_expense, 2) . '</td></tr>';
    echo '<tr class="total-row"><td>Net Savings</td><td>' . number_format($total_savings, 2) . '</td></tr>';
    echo '</table>';
    
    // Monthly breakdown
    echo '<h2>📅 Monthly Breakdown</h2>';
    echo '<table>';
    echo '<tr><th>Month</th><th>Income (PKR)</th><th>Expense (PKR)</th><th>Savings (PKR)</th></tr>';
    $stmtMonInc = $conn->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, SUM(amount_pkr) as total FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ? GROUP BY month ORDER BY month DESC");
    $stmtMonInc->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $monthlyInc = $stmtMonInc->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $stmtMonExp = $conn->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, amount, currency FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
    $stmtMonExp->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $expensesRaw = $stmtMonExp->fetchAll();
    $monthlyExp = [];
    foreach ($expensesRaw as $exp) {
        $month = $exp['month'];
        $converted = CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
        $monthlyExp[$month] = ($monthlyExp[$month] ?? 0) + $converted;
    }
    $allMonths = array_unique(array_merge(array_keys($monthlyInc), array_keys($monthlyExp)));
    rsort($allMonths);
    foreach ($allMonths as $month) {
        $inc = $monthlyInc[$month] ?? 0;
        $exp = $monthlyExp[$month] ?? 0;
        $sav = $inc - $exp;
        echo "<tr><td>{$month}</td><td>" . number_format($inc,2) . "</td><td>" . number_format($exp,2) . "</td><td>" . number_format($sav,2) . "</td></tr>";
    }
    echo '</table>';
    
    // Transactions
    echo '<h2>📋 Detailed Transactions</h2>';
    echo '<table>';
    echo '<tr><th>Type</th><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>Amount (PKR)</th><th>Date</th></tr>';
    
    $stmtInc = $conn->prepare("SELECT title, amount, amount_pkr, transaction_date FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC");
    $stmtInc->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $incomes = $stmtInc->fetchAll();
    foreach ($incomes as $inc) {
        echo "<tr style='background:#e6f7e6'><td>Income</td><td>{$inc['title']}</td><td>-</td><td>" . number_format($inc['amount'],2) . "</td><td>PKR</td><td>" . number_format($inc['amount_pkr'],2) . "</td><td>" . date('d M Y', strtotime($inc['transaction_date'])) . "</td></tr>";
    }
    
    $stmtExp = $conn->prepare("SELECT title, amount, currency, category, transaction_date FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC");
    $stmtExp->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
    $expenses = $stmtExp->fetchAll();
    foreach ($expenses as $exp) {
        $amountPkr = CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
        echo "<tr style='background:#ffe6e6'><td>Expense</td><td>{$exp['title']}</td><td>{$exp['category']}</td><td>" . number_format($exp['amount'],2) . "</td><td>{$exp['currency']}</td><td>" . number_format($amountPkr,2) . "</td><td>" . date('d M Y', strtotime($exp['transaction_date'])) . "</td></tr>";
    }
    echo '</table>';
    
    // AI Insight
    if ($savings_percent >= 30) {
        $ai_msg = "🌟 Excellent! You're saving more than 30% of your income. Keep it up!";
    } elseif ($savings_percent >= 15) {
        $ai_msg = "👍 Good job! You're saving " . round($savings_percent,1) . "% of your income.";
    } elseif ($savings_percent > 0) {
        $ai_msg = "📈 You're saving " . round($savings_percent,1) . "% of your income. Consider reducing non-essential expenses.";
    } else {
        $ai_msg = "⚠️ Your expenses exceed income. Review your spending habits.";
    }
    echo '<div class="ai-box"><strong>🧠 AI Insight:</strong> ' . $ai_msg . '</div>';
    
    echo '<p style="margin-top: 30px; font-size: 12px; color: gray;">Generated by ExpenseFlow on ' . date('Y-m-d H:i:s') . '</p>';
    echo '</body></html>';
    exit();
}

// ---- For HTML display (not export) ----
$stmtIncTotal = $conn->prepare("SELECT SUM(amount_pkr) as total FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
$stmtIncTotal->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$total_income = $stmtIncTotal->fetch()['total'] ?? 0;

$stmtExpTotal = $conn->prepare("SELECT amount, currency FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
$stmtExpTotal->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$expensesAll = $stmtExpTotal->fetchAll();
$total_expense = 0;
foreach ($expensesAll as $exp) {
    $total_expense += CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
}
$total_savings = $total_income - $total_expense;
$savings_percent = ($total_income > 0) ? ($total_savings / $total_income) * 100 : 0;

// Monthly totals for display
$stmtMonInc = $conn->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, SUM(amount_pkr) as total FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ? GROUP BY month ORDER BY month DESC");
$stmtMonInc->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$monthlyInc = $stmtMonInc->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtMonExp = $conn->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month, amount, currency FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
$stmtMonExp->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$expensesRaw = $stmtMonExp->fetchAll();
$monthlyExp = [];
foreach ($expensesRaw as $exp) {
    $month = $exp['month'];
    $converted = CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
    $monthlyExp[$month] = ($monthlyExp[$month] ?? 0) + $converted;
}
$allMonths = array_unique(array_merge(array_keys($monthlyInc), array_keys($monthlyExp)));
rsort($allMonths);

// Yearly totals
$stmtYearInc = $conn->prepare("SELECT YEAR(transaction_date) as year, SUM(amount_pkr) as total FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ? GROUP BY year ORDER BY year DESC");
$stmtYearInc->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$yearlyInc = $stmtYearInc->fetchAll(PDO::FETCH_KEY_PAIR);

$stmtYearExp = $conn->prepare("SELECT YEAR(transaction_date) as year, amount, currency FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ?");
$stmtYearExp->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$expensesYear = $stmtYearExp->fetchAll();
$yearlyExp = [];
foreach ($expensesYear as $exp) {
    $year = $exp['year'];
    $converted = CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
    $yearlyExp[$year] = ($yearlyExp[$year] ?? 0) + $converted;
}
$allYears = array_unique(array_merge(array_keys($yearlyInc), array_keys($yearlyExp)));
rsort($allYears);

// AI Insight message
if ($savings_percent >= 30) {
    $ai_insight = "Excellent! You're saving more than 30% of your income. Keep it up! 🎉";
    $ai_color = "#2ecc71";
} elseif ($savings_percent >= 15) {
    $ai_insight = "Good job! You're saving {$savings_percent}% of your income. Try to reach 30% for financial freedom. 📈";
    $ai_color = "#3498db";
} elseif ($savings_percent > 0) {
    $ai_insight = "You're saving {$savings_percent}% of your income. Consider reducing non-essential expenses to save more. 💡";
    $ai_color = "#f39c12";
} else {
    $ai_insight = "Your expenses exceed income. Review your spending habits and prioritize needs over wants. ⚠️";
    $ai_color = "#e74c3c";
}

// Income and expense lists for display
$stmtInc = $conn->prepare("SELECT title, amount, amount_pkr, transaction_date FROM income WHERE user_id = ? AND transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC");
$stmtInc->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$incomes = $stmtInc->fetchAll();

$stmtExp = $conn->prepare("SELECT title, amount, currency, category, transaction_date FROM expenses WHERE user_id = ? AND transaction_date BETWEEN ? AND ? ORDER BY transaction_date DESC");
$stmtExp->execute([$user_id, $from_date, $to_date . ' 23:59:59']);
$expenses = $stmtExp->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/expense-tracker/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Report | ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f4f7f9; padding: 40px 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .report-header { background: white; border-radius: 28px; padding: 24px 32px; margin-bottom: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        h1 { font-size: 32px; margin-bottom: 8px; color: #0B2545; }
        .user-info { margin-top: 16px; padding-top: 16px; border-top: 1px solid #eef2f6; color: #5F6C7D; }
        .filter-form { background: white; border-radius: 28px; padding: 20px 32px; margin-bottom: 24px; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 13px; font-weight: 500; color: #5F6C7D; }
        .filter-group input { padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 20px; font-size: 14px; }
        button, .btn { padding: 10px 20px; border-radius: 40px; font-weight: 500; border: none; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; background: #137A7F; color: white; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .toolbar { display: flex; gap: 12px; justify-content: flex-end; margin-bottom: 20px; }
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 32px; }
        .card { background: white; border-radius: 24px; padding: 20px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        .card h3 { font-size: 14px; color: #5F6C7D; margin-bottom: 8px; }
        .card p { font-size: 28px; font-weight: 700; color: #0B2545; }
        .section-title { font-size: 22px; font-weight: 600; margin: 32px 0 16px; color: #0B2545; border-left: 4px solid #137A7F; padding-left: 16px; }
        table { width: 100%; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.04); border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #eef2f6; }
        th { background: #f8fafc; font-weight: 600; color: #0B2545; }
        .ai-insight { background: white; border-radius: 20px; padding: 20px 24px; margin: 20px 0; border-left: 4px solid <?= $ai_color ?>; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
        /* Hide UI elements when printing (for PDF) */
        @media print {
            .toolbar, .filter-form, .back-link, .no-print { display: none !important; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .ai-insight { box-shadow: none; border: 1px solid #ddd; }
        }
        @media (max-width: 768px) { .filter-form { flex-direction: column; align-items: stretch; } th, td { font-size: 12px; padding: 8px 12px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="report-header">
        <h1>📄 Full Financial Report</h1>
        <div class="user-info">
            <strong><?= htmlspecialchars($user['name']) ?></strong> &nbsp;|&nbsp;
            <?= htmlspecialchars($user['email']) ?>
        </div>
    </div>

    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
        </div>
        <button type="submit">Apply Filter</button>
        <a href="view-report.php" class="btn-secondary" style="background:#e2e8f0; color:#334155;">Reset</a>
    </form>

    <!-- Toolbar with both Excel and PDF buttons -->
    <div class="toolbar">
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>" class="btn">📎 Export to Excel (Styled)</a>
        <button onclick="window.print();" class="btn">🖨️ Save as PDF / Print</button>
    </div>

    <div class="summary-cards">
        <div class="card"><h3>💰 Total Income (PKR)</h3><p><?= number_format($total_income, 2) ?></p></div>
        <div class="card"><h3>💸 Total Expense (PKR)</h3><p><?= number_format($total_expense, 2) ?></p></div>
        <div class="card"><h3>📈 Total Savings (PKR)</h3><p><?= number_format($total_savings, 2) ?></p></div>
    </div>

    <div class="ai-insight">
        <strong>🧠 AI Insight:</strong> <?= $ai_insight ?>
    </div>

    <h2 class="section-title">📅 Monthly Breakdown</h2>
    <table>
        <thead>
            <tr><th>Month</th><th>Income (PKR)</th><th>Expense (PKR)</th><th>Savings (PKR)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($allMonths as $month): 
            $inc = $monthlyInc[$month] ?? 0;
            $exp = $monthlyExp[$month] ?? 0;
            $sav = $inc - $exp;
        ?>
            <tr><td><?= $month ?></td><td><?= number_format($inc,2) ?></td><td><?= number_format($exp,2) ?></td><td><?= number_format($sav,2) ?></td></tr>
        <?php endforeach; ?>
        <?php if(empty($allMonths)) echo "<tr><td colspan='4'>No data for selected period.</td></tr>"; ?>
        </tbody>
    </table>

    <h2 class="section-title">📆 Yearly Breakdown</h2>
    <table>
        <thead>
            <tr><th>Year</th><th>Income (PKR)</th><th>Expense (PKR)</th><th>Savings (PKR)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($allYears as $year): 
            $incY = $yearlyInc[$year] ?? 0;
            $expY = $yearlyExp[$year] ?? 0;
            $savY = $incY - $expY;
        ?>
            <tr><td><?= $year ?></td><td><?= number_format($incY,2) ?></td><td><?= number_format($expY,2) ?></td><td><?= number_format($savY,2) ?></td></tr>
        <?php endforeach; ?>
        <?php if(empty($allYears)) echo "<tr><td colspan='4'>No data for selected period.</td></tr>"; ?>
        </tbody>
    </table>

    <h2 class="section-title">📋 Detailed Transactions</h2>
    <table>
        <thead>
            <tr><th>Type</th><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>Amount (PKR)</th><th>Date</th></tr>
        </thead>
        <tbody>
        <?php foreach ($incomes as $inc): ?>
            <tr style="background:#f0fdf4">
                <td>💰 Income</td>
                <td><?= htmlspecialchars($inc['title']) ?></td>
                <td>-</td>
                <td><?= number_format($inc['amount'],2) ?></td>
                <td>PKR</td>
                <td><?= number_format($inc['amount_pkr'],2) ?></td>
                <td><?= date('d M Y', strtotime($inc['transaction_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($expenses as $exp): 
            $amtPkr = CurrencyConverter::convert($exp['amount'], $exp['currency'], 'PKR');
        ?>
            <tr style="background:#fff5f5">
                <td>💸 Expense</td>
                <td><?= htmlspecialchars($exp['title']) ?></td>
                <td><?= htmlspecialchars($exp['category']) ?></td>
                <td><?= number_format($exp['amount'],2) ?></td>
                <td><?= htmlspecialchars($exp['currency']) ?></td>
                <td><?= number_format($amtPkr,2) ?></td>
                <td><?= date('d M Y', strtotime($exp['transaction_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if(empty($incomes) && empty($expenses)) echo "<tr><td colspan='7'>No transactions for selected period.</td></tr>"; ?>
        </tbody>
    </table>

    <div style="margin-top: 30px; text-align: center;">
        <a href="<?= $base_url ?>/dashboard.php" class="back-link btn" style="background:#137A7F;">← Back to Dashboard</a>
    </div>
</div>
</body>
</html>