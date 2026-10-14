<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/CurrencyConverter.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/personal/lib.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

$stmtUser = $conn->prepare("SELECT name, email, is_admin FROM users WHERE id = ?");
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

personal_ensure_schema($conn);
$type = in_array($_GET['type'] ?? 'all', ['all', 'income', 'expense'], true) ? (string) ($_GET['type'] ?? 'all') : 'all';

/* ---------- Data (shared by the page and the exports) ---------- */
$stmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, transaction_date, payment_method, note FROM income WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ? ORDER BY transaction_date DESC");
$stmt->execute([$user_id, $range_from, $range_to]);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, category, transaction_date, payment_method, note FROM expenses WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ? ORDER BY transaction_date DESC");
$stmt->execute([$user_id, $range_from, $range_to]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($type === 'income') { $expenses = []; }
if ($type === 'expense') { $incomes = []; }

$total_income = 0.0;
$total_expense = 0.0;
$monthlyInc = $monthlyExp = $yearlyInc = $yearlyExp = $catTotals = [];

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
    $cn = trim((string) $exp['category']) !== '' ? (string) $exp['category'] : 'Uncategorized';
    $catTotals[$cn] = ($catTotals[$cn] ?? 0) + $exp['pkr'];
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

/* ---------- CSV export (plain UTF-8 file; opens in Excel, Sheets, Numbers) ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="expense_report_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads it as UTF-8
    fputcsv($out, ['Type', 'Date', 'Title', 'Category', 'Payment method', 'Note', 'Amount', 'Currency', 'Amount (PKR)']);
    $rows = [];
    foreach ($incomes as $r) { $rows[] = ['Income', $r, '']; }
    foreach ($expenses as $r) { $rows[] = ['Expense', $r, (string) $r['category']]; }
    usort($rows, static fn($a, $b) => strcmp($b[1]['transaction_date'], $a[1]['transaction_date']));
    foreach ($rows as [$kind, $r, $cat]) {
        fputcsv($out, [
            $kind, substr($r['transaction_date'], 0, 10), spreadsheet_safe($r['title']), spreadsheet_safe($cat),
            (string) ($r['payment_method'] ?? ''), spreadsheet_safe($r['note'] ?? ''),
            number_format((float) $r['amount'], 2, '.', ''), (string) ($r['currency'] ?? 'PKR'), number_format((float) $r['pkr'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit();
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
<?php
arsort($catTotals);
$fmt = static fn(float $n): string => number_format($n, 2);
$maxMonth = 0.0;
foreach ($allMonths as $m) { $maxMonth = max($maxMonth, $monthlyInc[$m] ?? 0, $monthlyExp[$m] ?? 0); }
$catIcons = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book'];
$qs = static fn(array $extra = []) => http_build_query(array_merge(['from_date' => $from_date, 'to_date' => $to_date, 'type' => $type], $extra));

$rows = [];
foreach ($incomes as $r) { $r['kind'] = 'income'; $rows[] = $r; }
foreach ($expenses as $r) { $r['kind'] = 'expense'; $rows[] = $r; }
usort($rows, static fn($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']));

ef_page_start(['title' => 'Full Report', 'sub' => 'Filter, analyse and export', 'active' => 'rep', 'user' => $user]);
?>
<form method="GET" class="g card rf no-print" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
    <div class="fl" style="margin:0"><label for="from_date">From</label><input class="inp" id="from_date" type="date" name="from_date" value="<?= e($from_date) ?>"></div>
    <div class="fl" style="margin:0"><label for="to_date">To</label><input class="inp" id="to_date" type="date" name="to_date" value="<?= e($to_date) ?>"></div>
    <div class="fl" style="margin:0"><label for="type">Type</label>
        <select class="inp" id="type" name="type">
            <?php foreach (['all' => 'All', 'income' => 'Income only', 'expense' => 'Expenses only'] as $v => $t): ?>
                <option value="<?= e($v) ?>"<?= $type === $v ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="btn" type="submit">Apply</button>
    <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn o" href="?<?= e($qs(['export' => 'csv'])) ?>"><?= ef_icon('dl') ?> CSV</a>
        <a class="btn o" href="?<?= e($qs(['export' => 'excel'])) ?>"><?= ef_icon('dl') ?> Excel</a>
        <button class="btn o" type="button" onclick="window.print()"><?= ef_icon('dl') ?> PDF</button>
    </span>
</form>

<div class="G4">
    <div class="g card"><div class="k">Total income (PKR)</div><div class="v in"><?= $fmt($total_income) ?></div></div>
    <div class="g card"><div class="k">Total expense (PKR)</div><div class="v ex"><?= $fmt($total_expense) ?></div></div>
    <div class="g card"><div class="k">Net savings (PKR)</div><div class="v"><?= $fmt($total_savings) ?></div></div>
    <div class="g card"><div class="k">Savings rate</div><div class="v"><?= e((string) $savings_percent) ?>%</div></div>
</div>
<div class="g card"><div class="k">Smart insight</div><div style="font-weight:600;margin-top:4px"><?= e($ai_insight) ?></div></div>

<div class="G2">
    <div class="g card">
        <div class="t">Monthly comparison <span class="k">Income vs expense (PKR)</span></div>
        <?php foreach ($allMonths as $m): $i = $monthlyInc[$m] ?? 0; $x = $monthlyExp[$m] ?? 0; ?>
            <div style="margin-bottom:12px;font-size:.8rem">
                <div style="display:flex;justify-content:space-between"><b><?= e(date('M Y', strtotime($m . '-01'))) ?></b><span class="<?= $i - $x >= 0 ? 'in' : 'ex' ?>"><?= $fmt($i - $x) ?></span></div>
                <div class="bar"><i style="width:<?= $maxMonth > 0 ? round($i / $maxMonth * 100, 1) : 0 ?>%;background:#10b981"></i></div>
                <div class="bar"><i style="width:<?= $maxMonth > 0 ? round($x / $maxMonth * 100, 1) : 0 ?>%;background:#f97316"></i></div>
            </div>
        <?php endforeach; if (!$allMonths): ?><p class="k">No data in this period.</p><?php endif; ?>
    </div>
    <div class="g card">
        <div class="t">Category breakdown</div>
        <?php foreach ($catTotals as $name => $val): $pct = $total_expense > 0 ? $val / $total_expense * 100 : 0; ?>
            <div style="margin-bottom:12px;font-size:.8rem">
                <div style="display:flex;justify-content:space-between;gap:8px"><span><?= ef_icon($catIcons[$name] ?? 'more') ?> <?= e($name) ?></span><b><?= $fmt((float) $val) ?> · <?= round($pct) ?>%</b></div>
                <div class="bar"><i style="width:<?= round($pct, 1) ?>%;background:var(--a1)"></i></div>
            </div>
        <?php endforeach; if (!$catTotals): ?><p class="k">No expenses in this period.</p><?php endif; ?>
    </div>
</div>

<?php if (count($allYears) > 1): ?>
<div class="g card">
    <div class="t">Yearly breakdown</div>
    <?php foreach ($allYears as $y): $i = $yearlyInc[$y] ?? 0; $x = $yearlyExp[$y] ?? 0; ?>
        <div class="row"><span><b><?= e($y) ?></b><small>Income <?= $fmt($i) ?> · Expense <?= $fmt($x) ?></small></span><b class="<?= $i - $x >= 0 ? 'in' : 'ex' ?>"><?= $fmt($i - $x) ?></b></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="g card">
    <div class="t">All transactions <span class="k"><?= count($rows) ?> entries</span></div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;min-width:620px">
        <thead><tr style="text-align:left;color:var(--mu);font-size:.72rem;text-transform:uppercase"><th style="padding:8px 6px">Date</th><th>Title</th><th>Category</th><th>Method</th><th style="text-align:right">Amount</th><th style="text-align:right">PKR</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $isInc = $r['kind'] === 'income'; ?>
            <tr style="border-top:1px solid rgba(91,107,128,.14)">
                <td style="padding:9px 6px;white-space:nowrap"><?= e(date('d M Y', strtotime($r['transaction_date']))) ?></td>
                <td><?= e($r['title']) ?><?php if (!empty($r['note'])): ?><small style="display:block;color:var(--mu)"><?= e($r['note']) ?></small><?php endif; ?></td>
                <td><?= $isInc ? '<span class="tag tg">Income</span>' : e($r['category']) ?></td>
                <td><?= e((string) ($r['payment_method'] ?? '')) ?></td>
                <td style="text-align:right;white-space:nowrap" class="<?= $isInc ? 'in' : 'ex' ?>"><?= $isInc ? '+' : '−' ?> <?= $fmt((float) $r['amount']) ?> <?= e($r['currency'] ?? 'PKR') ?></td>
                <td style="text-align:right;white-space:nowrap"><?= $fmt((float) $r['pkr']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (!$rows): ?><p class="k" style="padding:12px 0">No transactions in this period.</p><?php endif; ?>
</div>
<?php
ef_page_end(['active' => 'rep']);
