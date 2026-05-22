<?php
session_start();
require_once "../config/db.php";
require_once "../tcpdf/tcpdf.php";

if(!isset($_SESSION["user_id"])){
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";
$month = $_GET['month'] ?? date('m');
$year  = $_GET['year'] ?? date('Y');

// ----- Handle client-side time (from browser) -----
$client_time = $_GET['client_time'] ?? null;
if ($client_time && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $client_time)) {
    $timestamp = $client_time;
} else {
    date_default_timezone_set('Asia/Karachi');
    $timestamp = date('Y-m-d H:i:s');
}

// Fetch incomes
$incomeStmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, transaction_date FROM income WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=? ORDER BY transaction_date ASC");
$incomeStmt->execute([$user_id,$month,$year]);
$incomes = $incomeStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch expenses
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

// AI Insight
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

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('ExpenseFlow');
$pdf->SetAuthor($user_name);
$pdf->SetTitle('Financial Report');
$pdf->SetHeaderData('', 0, 'Financial Report', "User: $user_name\nPeriod: " . date('F Y', mktime(0,0,0,$month,1,$year)) . "\nGenerated: $timestamp");
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(TRUE, 25);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// HTML content
$html = '
<style>
    h3 { background-color: #e2e8f0; padding: 5px; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 15px; }
    th { background-color: #0f766e; color: white; padding: 6px; }
    td { border: 1px solid #cbd5e1; padding: 5px; }
    .totals { background-color: #f1f5f9; padding: 8px; border-radius: 5px; }
</style>

<h3>AI Insight</h3>
<p>'.htmlspecialchars($insight).'</p>

<h3>Monthly Income</h3>
<table border="0" cellpadding="4">
    <thead><tr><th>Title</th><th>Amount</th><th>Currency</th><th>PKR</th><th>Date</th></tr></thead>
    <tbody>';
foreach($incomes as $inc){
    $html .= '<tr><td>'.htmlspecialchars($inc['title']).'</td><td>'.$inc['amount'].'</td><td>'.$inc['currency'].'</td><td>'.$inc['amount_pkr'].'</td><td>'.$inc['transaction_date'].'</td></tr>';
}
$html .= '</tbody>
</table>

<h3>Monthly Expenses</h3>
<table border="0" cellpadding="4">
    <thead><tr><th>Title</th><th>Category</th><th>Amount</th><th>Currency</th><th>PKR</th><th>Date</th></tr></thead>
    <tbody>';
foreach($expenses as $exp){
    $html .= '<tr><td>'.htmlspecialchars($exp['title']).'</td><td>'.$exp['category'].'</td><td>'.$exp['amount'].'</td><td>'.$exp['currency'].'</td><td>'.$exp['amount_pkr'].'</td><td>'.$exp['transaction_date'].'</td></tr>';
}
$html .= '</tbody>
</table>

<h3>Monthly Totals</h3>
<div class="totals">
    <strong>Income:</strong> '.$month_income.'<br>
    <strong>Expense:</strong> '.$month_expense.'<br>
    <strong>Savings:</strong> '.$month_savings.'
</div>

<h3>Yearly Totals ('.$year.')</h3>
<div class="totals">
    <strong>Income:</strong> '.$year_income.'<br>
    <strong>Expense:</strong> '.$year_expense.'<br>
    <strong>Savings:</strong> '.$year_savings.'
</div>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('Financial_Report_'.$month.'_'.$year.'.pdf', 'D');
exit;
?>