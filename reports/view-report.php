<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/CurrencyConverter.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

$stmtUser = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

// Dates come from the URL, so they are validated; anything invalid falls back to "this year so far".
$from_date = valid_date($_GET['from_date'] ?? '') ?? date('Y-01-01');
$to_date   = valid_date($_GET['to_date'] ?? '') ?? date('Y-m-d');
if ($from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}
$range_from = $from_date . ' 00:00:00';
$range_to   = (new DateTimeImmutable($to_date))->modify('+1 day')->format('Y-m-d H:i:s'); // exclusive

/* ---------- Data (shared by the page and the Excel export) ---------- */
$stmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, transaction_date FROM income WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ? ORDER BY transaction_date DESC");
$stmt->execute([$user_id, $range_from, $range_to]);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, category, transaction_date FROM expenses WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ? ORDER BY transaction_date DESC");
$stmt->execute([$user_id, $range_from, $range_to]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_income = 0.0;
$total_expense = 0.0;
$monthlyInc = $monthlyExp = $yearlyInc = $yearlyExp = [];

foreach ($incomes as &$inc) {
    $inc['pkr'] = (float) ($inc['amount_pkr'] ?? 0);
    $total_income += $inc['pkr'];
    $m = substr($inc['transaction_date'], 0, 7);
    $y = substr($inc['transaction_date'], 0, 4);
    $monthlyInc[$m] = ($monthlyInc[$m] ?? 0) + $inc['pkr'];
    $yearlyInc[$y] = ($yearlyInc[$y] ?? 0) + $inc['pkr'];
}
unset($inc);

foreach ($expenses as &$exp) {
    $exp['pkr'] = CurrencyConverter::expenseInPkr($exp);
    $total_expense += $exp['pkr'];
    $m = substr($exp['transaction_date'], 0, 7);
    $y = substr($exp['transaction_date'], 0, 4);
    $monthlyExp[$m] = ($monthlyExp[$m] ?? 0) + $exp['pkr'];
    $yearlyExp[$y] = ($yearlyExp[$y] ?? 0) + $exp['pkr'];
}
unset($exp);

$total_savings = $total_income - $total_expense;
$savings_percent = ($total_income > 0) ? round(($total_savings / $total_income) * 100, 1) : 0;

$allMonths = array_unique(array_merge(array_keys($monthlyInc), array_keys($monthlyExp)));
rsort($allMonths);
$allYears = array_unique(array_merge(array_keys($yearlyInc), array_keys($yearlyExp)));
rsort($allYears);

if ($savings_percent >= 30) {
    $ai_insight = "Excellent! You're saving more than 30% of your income. Keep it up! 🎉";
} elseif ($savings_percent >= 15) {
    $ai_insight = "Good job! You're saving {$savings_percent}% of your income. Try to reach 30% for financial freedom. 📈";
} elseif ($savings_percent > 0) {
    $ai_insight = "You're saving {$savings_percent}% of your income. Consider reducing non-essential expenses to save more. 💡";
} else {
    $ai_insight = "Your expenses exceed income. Review your spending habits and prioritize needs over wants. ⚠️";
}

/* ---------- Excel export (an HTML table that Excel opens) ---------- */
if (($_GET['export'] ?? '') === 'excel') {
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"expense_report_" . date('Y-m-d') . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $n = static fn($v) => number_format((float) $v, 2);

    echo '<html><head><meta charset="UTF-8"><title>ExpenseFlow Report</title><style>' . '
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
        ' . '</style></head><body>';
    echo '<div class="header"><h1>📊 ExpenseFlow Financial Report</h1>';
    echo '<div class="user-info"><strong>' . e($user['name']) . '</strong> | ' . e($user['email']) . '</div>';
    echo '<p>Period: ' . e($from_date) . ' to ' . e($to_date) . '</p></div>';

    echo '<h2>💰 Summary</h2><table class="summary-table"><tr><th>Metric</th><th>Amount (PKR)</th></tr>';
    echo '<tr><td>Total Income</td><td>' . $n($total_income) . '</td></tr>';
    echo '<tr><td>Total Expense</td><td>' . $n($total_expense) . '</td></tr>';
    echo '<tr class="total-row"><td>Net Savings</td><td>' . $n($total_savings) . '</td></tr></table>';

    echo '<h2>📅 Monthly Breakdown</h2><table><tr><th>Month</th><th>Income (PKR)</th><th>Expense (PKR)</th><th>Savings (PKR)</th></tr>';
    foreach ($allMonths as $m) {
        $i = $monthlyInc[$m] ?? 0;
        $x = $monthlyExp[$m] ?? 0;
        echo '<tr><td>' . e($m) . '</td><td>' . $n($i) . '</td><td>' . $n($x) . '</td><td>' . $n($i - $x) . '</td></tr>';
    }
    echo '</table>';

    echo '<h2>📋 Detailed Transactions</h2><table><tr><th>Type</th><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>Amount (PKR)</th><th>Date</th></tr>';
    foreach ($incomes as $r) {
        echo '<tr class="income-row"><td>Income</td><td>' . e(spreadsheet_safe($r['title'])) . '</td><td>-</td><td>' . $n($r['amount']) . '</td><td>' . e($r['currency'] ?? 'PKR') . '</td><td>' . $n($r['pkr']) . '</td><td>' . e(date('d M Y', strtotime($r['transaction_date']))) . '</td></tr>';
    }
    foreach ($expenses as $r) {
        echo '<tr class="expense-row"><td>Expense</td><td>' . e(spreadsheet_safe($r['title'])) . '</td><td>' . e(spreadsheet_safe($r['category'])) . '</td><td>' . $n($r['amount']) . '</td><td>' . e($r['currency']) . '</td><td>' . $n($r['pkr']) . '</td><td>' . e(date('d M Y', strtotime($r['transaction_date']))) . '</td></tr>';
    }
    echo '</table>';

    echo '<div class="ai-box"><strong>🧠 Smart Insight:</strong> ' . e($ai_insight) . '</div>';
    echo '<p style="margin-top: 30px; font-size: 12px; color: gray;">Generated by ExpenseFlow on ' . e(date('Y-m-d H:i:s')) . '</p>';
    echo '</body></html>';
    exit();
}

/** Stops spreadsheet apps from running a cell as a formula (e.g. a title starting with "=" or "@"). */
function spreadsheet_safe($value): string
{
    $value = (string) $value;
    return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) ? "'" . $value : $value;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
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
            <strong><?= e($user['name']) ?></strong> &nbsp;|&nbsp;
            <?= e($user['email']) ?>
        </div>
    </div>

    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="from_date" value="<?= e($from_date) ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="to_date" value="<?= e($to_date) ?>">
        </div>
        <button type="submit">Apply Filter</button>
        <a href="view-report.php" class="btn-secondary" style="background:#e2e8f0; color:#334155;">Reset</a>
    </form>

    <!-- Toolbar with both Excel and PDF buttons -->
    <div class="toolbar">
        <a href="?<?= e(http_build_query(array_merge($_GET, ['export'=>'excel']))) ?>" class="btn">📎 Export to Excel (Styled)</a>
        <button onclick="window.print();" class="btn">🖨️ Save as PDF / Print</button>
    </div>

    <div class="summary-cards">
        <div class="card"><h3>💰 Total Income (PKR)</h3><p><?= number_format($total_income, 2) ?></p></div>
        <div class="card"><h3>💸 Total Expense (PKR)</h3><p><?= number_format($total_expense, 2) ?></p></div>
        <div class="card"><h3>📈 Total Savings (PKR)</h3><p><?= number_format($total_savings, 2) ?></p></div>
    </div>

    <div class="ai-insight">
        <strong>🧠 Smart Insight:</strong> <?= e($ai_insight) ?>
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
            <tr><td><?= e($month) ?></td><td><?= number_format($inc,2) ?></td><td><?= number_format($exp,2) ?></td><td><?= number_format($sav,2) ?></td></tr>
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
            <tr><td><?= e($year) ?></td><td><?= number_format($incY,2) ?></td><td><?= number_format($expY,2) ?></td><td><?= number_format($savY,2) ?></td></tr>
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
                <td><?= e($inc['title']) ?></td>
                <td>-</td>
                <td><?= number_format($inc['amount'],2) ?></td>
                <td><?= e($inc['currency'] ?? 'PKR') ?></td>
                <td><?= number_format($inc['pkr'],2) ?></td>
                <td><?= date('d M Y', strtotime($inc['transaction_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($expenses as $exp): 
        ?>
            <tr style="background:#fff5f5">
                <td>💸 Expense</td>
                <td><?= e($exp['title']) ?></td>
                <td><?= e($exp['category']) ?></td>
                <td><?= number_format($exp['amount'],2) ?></td>
                <td><?= e($exp['currency']) ?></td>
                <td><?= number_format($exp['pkr'],2) ?></td>
                <td><?= date('d M Y', strtotime($exp['transaction_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if(empty($incomes) && empty($expenses)) echo "<tr><td colspan='7'>No transactions for selected period.</td></tr>"; ?>
        </tbody>
    </table>

    <div style="margin-top: 30px; text-align: center;">
        <a href="<?= e($base_url) ?>/dashboard.php" class="back-link btn" style="background:#137A7F;">← Back to Dashboard</a>
    </div>
</div>
</body>
</html>