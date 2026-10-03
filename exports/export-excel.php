<?php
session_start();
require_once "../config/db.php";
require_once "../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if(!isset($_SESSION["user_id"])){
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "User";

$month = $_GET['month'] ?? date('m');
$year  = $_GET['year'] ?? date('Y');

// Fetch data (same as before)
$incomeStmt = $conn->prepare("SELECT title, amount, currency, amount_pkr, transaction_date FROM income WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=? ORDER BY transaction_date ASC");
$incomeStmt->execute([$user_id,$month,$year]);
$incomes = $incomeStmt->fetchAll(PDO::FETCH_ASSOC);

$expenseStmt = $conn->prepare("SELECT title, category, amount, currency, amount_pkr, transaction_date FROM expenses WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=? ORDER BY transaction_date ASC");
$expenseStmt->execute([$user_id,$month,$year]);
$expenses = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$month_income = array_sum(array_column($incomes,'amount'));
$month_expense = array_sum(array_column($expenses,'amount'));
$month_savings = $month_income - $month_expense;

// Year totals
$yInc = $conn->prepare("SELECT SUM(amount) as total FROM income WHERE user_id=? AND YEAR(transaction_date)=?");
$yInc->execute([$user_id,$year]);
$year_income = $yInc->fetch()['total'] ?? 0;
$yExp = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id=? AND YEAR(transaction_date)=?");
$yExp->execute([$user_id,$year]);
$year_expense = $yExp->fetch()['total'] ?? 0;
$year_savings = $year_income - $year_expense;

// Insight
$prevMonth = $month-1; $prevYear = $year;
if($prevMonth==0){ $prevMonth=12; $prevYear--; }
$prevExp = $conn->prepare("SELECT SUM(amount) as total FROM expenses WHERE user_id=? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=?");
$prevExp->execute([$user_id,$prevMonth,$prevYear]);
$prev_expense = $prevExp->fetch()['total'] ?? 0;
$insight = "No previous data available.";
if($prev_expense > 0){
    $change = (($month_expense - $prev_expense)/$prev_expense)*100;
    if($change < 0) $insight = "🔥 You saved ".abs(round($change,1))."% more than last month.";
    else $insight = "⚠ You spent ".round($change,1)."% more than last month.";
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Financial Report");

// Style helpers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$titleStyle = ['font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]];

// Title
$sheet->setCellValue('A1', 'Financial Report');
$sheet->mergeCells('A1:F1');
$sheet->getStyle('A1')->applyFromArray($titleStyle);
$sheet->setCellValue('A2', "User: $user_name");
$sheet->mergeCells('A2:F2');

// AI Insight
$sheet->setCellValue('A4', 'AI INSIGHT:');
$sheet->setCellValue('B4', $insight);
$sheet->getStyle('A4')->getFont()->setBold(true);
$sheet->mergeCells('B4:F4');

// Monthly Income Table
$row = 6;
$sheet->setCellValue("A$row", "MONTHLY INCOME");
$sheet->mergeCells("A$row:F$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$row++;
$headers = ['Title','Amount','Currency','PKR','Date'];
$col = 'A';
foreach($headers as $h){
    $sheet->setCellValue($col++.$row, $h);
}
$sheet->getStyle("A$row:F$row")->applyFromArray($headerStyle);
$row++;
foreach($incomes as $inc){
    $sheet->setCellValue("A$row", $inc['title']);
    $sheet->setCellValue("B$row", $inc['amount']);
    $sheet->setCellValue("C$row", $inc['currency']);
    $sheet->setCellValue("D$row", $inc['amount_pkr']);
    $sheet->setCellValue("E$row", $inc['transaction_date']);
    $row++;
}

// Monthly Expenses Table
$row += 2;
$sheet->setCellValue("A$row", "MONTHLY EXPENSES");
$sheet->mergeCells("A$row:F$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$row++;
$headers = ['Title','Category','Amount','Currency','PKR','Date'];
$col = 'A';
foreach($headers as $h){
    $sheet->setCellValue($col++.$row, $h);
}
$sheet->getStyle("A$row:F$row")->applyFromArray($headerStyle);
$row++;
foreach($expenses as $exp){
    $sheet->setCellValue("A$row", $exp['title']);
    $sheet->setCellValue("B$row", $exp['category']);
    $sheet->setCellValue("C$row", $exp['amount']);
    $sheet->setCellValue("D$row", $exp['currency']);
    $sheet->setCellValue("E$row", $exp['amount_pkr']);
    $sheet->setCellValue("F$row", $exp['transaction_date']);
    $row++;
}

// Monthly Totals
$row += 2;
$sheet->setCellValue("A$row", "MONTHLY TOTALS");
$sheet->mergeCells("A$row:B$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$row++;
$sheet->setCellValue("A$row", "Income")->setCellValue("B$row", $month_income);
$row++;
$sheet->setCellValue("A$row", "Expense")->setCellValue("B$row", $month_expense);
$row++;
$sheet->setCellValue("A$row", "Savings")->setCellValue("B$row", $month_savings);

// Yearly Totals
$row += 2;
$sheet->setCellValue("A$row", "YEARLY TOTALS");
$sheet->mergeCells("A$row:B$row");
$sheet->getStyle("A$row")->getFont()->setBold(true);
$row++;
$sheet->setCellValue("A$row", "Income")->setCellValue("B$row", $year_income);
$row++;
$sheet->setCellValue("A$row", "Expense")->setCellValue("B$row", $year_expense);
$row++;
$sheet->setCellValue("A$row", "Savings")->setCellValue("B$row", $year_savings);

// Auto size columns
foreach(range('A','F') as $col){
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Download
$filename = "Financial_Report_".$month."_".$year.".xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'.$filename.'"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>