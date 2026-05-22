<?php
session_start();
require_once "../config/db.php";

if(!isset($_SESSION["user_id"])){
    header("Location: ../auth/login.php");
    exit();
}
$user_id = $_SESSION["user_id"];
$user_name = $_SESSION["user_name"];
$month = $_GET['month'] ?? date('m');
$year  = $_GET['year'] ?? date('Y');

// Fetch data (same as before – omitted for brevity)
$incomeStmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, transaction_date FROM income WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=? ORDER BY transaction_date ASC");
$incomeStmt->execute([$user_id,$month,$year]);
$incomes = $incomeStmt->fetchAll(PDO::FETCH_ASSOC);
$expenseStmt = $conn->prepare("SELECT title, category, amount, currency, amount_pkr, transaction_date FROM expenses WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=? ORDER BY transaction_date ASC");
$expenseStmt->execute([$user_id,$month,$year]);
$expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);
$month_income = array_sum(array_column($incomes,'amount'));
$month_expense = array_sum(array_column($expenses,'amount'));
$month_savings = $month_income - $month_expense;
$yInc = $conn->prepare("SELECT SUM(amount) as total FROM income WHERE user_id=? AND YEAR(transaction_date)=?");
$yInc->execute([$user_id,$year]);
$year_income = $yInc->fetch()['total'] ?? 0;
$yExp = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id=? AND YEAR(transaction_date)=?");
$yExp->execute([$user_id,$year]);
$year_expense = $yExp->fetch()['total'] ?? 0;
$year_savings = $year_income - $year_expense;

$prevMonth = $month-1; $prevYear = $year;
if($prevMonth==0){ $prevMonth=12; $prevYear--; }
$prevExp = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=?");
$prevExp->execute([$user_id,$prevMonth,$prevYear]);
$prev_expense = $prevExp->fetch()['total'] ?? 0;
$insight = "No previous data available.";
if($prev_expense > 0){
    $change = (($month_expense - $prev_expense)/$prev_expense)*100;
    if($change < 0) $insight = "You saved ".abs(round($change,1))."% more than last month.";
    else $insight = "You spent ".round($change,1)."% more than last month.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>View Report - ExpenseFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/expense-tracker/assets/responsive.css">
    <style>
        body { background: #f5f7fc; padding: 1rem; font-family: 'Inter', sans-serif; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 32px; padding: 2rem; box-shadow: 0 8px 20px rgba(0,0,0,0.05); }
        h1 { color: #0f172a; }
        .filters { display: flex; gap: 1rem; align-items: flex-end; margin: 1.5rem 0; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; }
        select, button { padding: 0.5rem 1rem; border-radius: 40px; border: 1px solid #cbd5e1; background: white; }
        button { background: #0f766e; color: white; border: none; cursor: pointer; }
        .download-buttons { display: flex; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
        .download-btn { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; padding: 8px 20px; border-radius: 40px; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
        th { background: #0f766e; color: white; padding: 10px; text-align: left; }
        td { border: 1px solid #e2e8f0; padding: 8px; }
        .section-title { font-size: 1.2rem; font-weight: 600; margin: 1.5rem 0 0.5rem; }
        .totals { background: #f1f5f9; padding: 1rem; border-radius: 16px; margin: 1rem 0; }
        .insight-box { background: #e2e8f0; padding: 1rem; border-radius: 16px; margin: 1rem 0; }
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .filters { flex-direction: column; align-items: stretch; }
            .download-buttons { flex-direction: column; }
            .download-btn { text-align: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📄 Financial Report</h1>
    <p>User: <?= htmlspecialchars($user_name) ?> | Period: <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></p>
    <form method="GET" class="filters">
        <div class="filter-group"><label>Month</label><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=($month==$m)?'selected':''?>><?=date('F', mktime(0,0,0,$m,1))?></option><?php endfor; ?></select></div>
        <div class="filter-group"><label>Year</label><select name="year"><?php for($y=2023;$y<=2035;$y++): ?><option value="<?=$y?>" <?=($year==$y)?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
        <button type="submit">View Report</button>
    </form>
    <div class="download-buttons">
        <a href="../exports/export-excel.php?month=<?=$month?>&year=<?=$year?>" class="download-btn">📊 Download Excel</a>
        <a href="../exports/export-pdf-tcpdf.php?month=<?=$month?>&year=<?=$year?>" class="download-btn">📑 Download PDF</a>
    </div>
    <div class="insight-box"><strong>🧠 AI Insight:</strong> <?= htmlspecialchars($insight) ?></div>

    <div class="section-title">💰 Monthly Income</div>
    <div class="table-responsive"><?php echo getIncomeTable($incomes); ?></div>
    <div class="section-title">💸 Monthly Expenses</div>
    <div class="table-responsive"><?php echo getExpenseTable($expenses); ?></div>
    <div class="section-title">📊 Monthly Totals</div>
    <div class="totals">Income: <?= $month_income ?><br>Expense: <?= $month_expense ?><br>Savings: <?= $month_savings ?></div>
    <div class="section-title">📅 Yearly Totals (<?= $year ?>)</div>
    <div class="totals">Income: <?= $year_income ?><br>Expense: <?= $year_expense ?><br>Savings: <?= $year_savings ?></div>
</div>
</body>
</html>
<?php
function getIncomeTable($incomes){
    if(empty($incomes)) return '<p>No income records.</p>';
    $html = '<table><thead><tr><th>Title</th><th>Amount</th><th>Currency</th><th>PKR</th><th>Date</th></tr></thead><tbody>';
    foreach($incomes as $inc) $html .= '<tr><td>'.htmlspecialchars($inc['title']).'</td><td>'.$inc['amount'].'</td><td>'.$inc['currency'].'</td><td>'.$inc['amount_pkr'].'</td><td>'.$inc['transaction_date'].'</td></tr>';
    $html .= '</tbody></table>';
    return $html;
}
function getExpenseTable($expenses){
    if(empty($expenses)) return '<p>No expense records.</p>';
    $html = '<table><thead><tr><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>PKR</th><th>Date</th></tr></thead><tbody>';
    foreach($expenses as $exp) $html .= '<tr><td>'.htmlspecialchars($exp['title']).'</td><td>'.$exp['category'].'</td><td>'.$exp['amount'].'</td><td>'.$exp['currency'].'</td><td>'.$exp['amount_pkr'].'</td><td>'.$exp['transaction_date'].'</td></tr>';
    $html .= '</tbody></table>';
    return $html;
}
?>