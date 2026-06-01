<?php
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

/* check user actually exists */
$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$is_admin = (bool)$user['is_admin'];

/* ---------- FILTER LOGIC ---------- */
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
$incomes = [];
$expenses = [];
if ($use_date_range) {
    $display_currency = "USD";
    $date_condition = "transaction_date BETWEEN ? AND ?";
    $date_params = [$from_date, $to_date . ' 23:59:59'];

    $stmtInc = $conn->prepare("SELECT amount_pkr, preferred_currency FROM income WHERE user_id = ? AND $date_condition");
    $stmtInc->execute(array_merge([$user_id], $date_params));
    $incomesRaw = $stmtInc->fetchAll();

    foreach ($incomesRaw as $inc) {
        $total_income += CurrencyConverter::convert(
            $inc['amount_pkr'],
            $inc['preferred_currency'] ?? 'PKR',
            'USD'
        );
    }

    $stmtExp = $conn->prepare("SELECT amount, preferred_currency FROM expenses WHERE user_id = ? AND $date_condition");
    $stmtExp->execute(array_merge([$user_id], $date_params));
    $expensesRaw = $stmtExp->fetchAll();

    foreach ($expensesRaw as $exp) {
        $total_expense += CurrencyConverter::convert(
            $exp['amount'],
            $exp['preferred_currency'] ?? 'PKR',
            'USD'
        );
    }

    $balance = $total_income - $total_expense;
    $days = (strtotime($to_date) - strtotime($from_date)) / 86400 + 1;
    $avgDailySpend = ($total_expense > 0 && $days > 0) ? round($total_expense / $days, 2) : 0;

} else {

    $year_month = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);

   $stmt = $conn->prepare("SELECT base_currency FROM `user_monthly_currency` WHERE user_id = ? AND `year_month` = ?");
    $stmt->execute([$user_id, $year_month]);
    $base_currency = $stmt->fetchColumn();

    $stmtExp = $conn->prepare("SELECT amount, preferred_currency FROM expenses WHERE user_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?");
    $stmtExp->execute([$user_id, $month, $year]);
    $expensesRaw = $stmtExp->fetchAll();

    if (!$base_currency) {
        $display_currency = "USD";

        foreach ($expensesRaw as $exp) {
            $total_expense += CurrencyConverter::convert(
                $exp['amount'],
                $exp['preferred_currency'] ?? 'PKR',
                'USD'
            );
        }

        $balance = -$total_expense;
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $avgDailySpend = ($total_expense > 0) ? round($total_expense / $daysInMonth, 2) : 0;

    } else {
        $display_currency = $base_currency;

        $stmtInc = $conn->prepare("SELECT amount_pkr, preferred_currency FROM income WHERE user_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?");
        $stmtInc->execute([$user_id, $month, $year]);
        $incomesRaw = $stmtInc->fetchAll();

        foreach ($incomesRaw as $inc) {
            $total_income += CurrencyConverter::convert(
                $inc['amount_pkr'],
                $inc['preferred_currency'] ?? $base_currency,
                $base_currency
            );
        }

        foreach ($expensesRaw as $exp) {
            $total_expense += CurrencyConverter::convert(
                $exp['amount'],
                $exp['preferred_currency'] ?? $base_currency,
                $base_currency
            );
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>ExpenseFlow | Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/client-time.js"></script>
    <link rel="stylesheet" href="assets/dashboard.css">
    <style>
        /* Additional animations & UI improvements */
        .sidebar a {
            transition: all 0.25s cubic-bezier(0.2, 0, 0, 1);
        }
        .sidebar a:hover {
            background: #e2e8f0;
            transform: translateX(6px) scale(1.02);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .menu-toggle {
            transition: 0.2s;
        }
        .menu-toggle:active {
            transform: scale(0.95);
        }
        @media (max-width: 768px) {
            .sidebar a {
                padding: 12px 16px;
                margin: 4px 0;
                border-radius: 16px;
            }
            .sidebar a:active {
                background: #cbd5e1;
                transform: scale(0.98);
            }
        }
        .profile-btn, .admin-btn {
            transition: 0.2s;
        }
        .profile-btn:hover, .admin-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="logo">ExpenseFlow</div>
    <a href="#">📊 Dashboard</a>
   <a href="<?= $base_url ?>/income/add-income.php">➕ Add Income</a>
    <a href="<?= $base_url ?>/expense/add-expense.php">➖ Add Expense</a>
    <a href="<?= $base_url ?>/reports/view-report.php">📄 Full Report</a>
    <a href="<?= $base_url ?>/auth/logout.php" data-log>🚪 Logout</a>
    <a href="<?= $base_url ?>/auth/delete-account.php" onclick="return confirm('Delete account permanently?')" style="color:#ef4444;">🗑 Delete Account</a>
</div>

<div class="main">
    <!-- Top bar: welcome on left, profile+admin on right -->
    <div class="top-bar">
        <div class="welcome">
            <h1>Welcome back, <?php echo htmlspecialchars($_SESSION["user_name"]); ?> 👋</h1>
            <p><?php echo $use_date_range ? "Custom range: $from_date to $to_date (converted to USD)" : date('F Y', mktime(0,0,0,$month,1,$year)) . " (base: $display_currency)"; ?></p>
        </div>
        <div class="btn-group" style="display: flex; gap: 12px;">
            <a href="<?= $base_url ?>/profile.php" class="profile-btn" style="background: linear-gradient(135deg, #4a5568, #2d3748); padding: 8px 20px; border-radius: 40px; color: white; text-decoration: none; font-weight: 500;">👤 Profile</a>
            <?php if ($is_admin): ?>
                <a href="<?= $base_url ?>/admin/index.php" class="admin-btn" style="background: linear-gradient(135deg, #0f766e, #1d4ed8); padding: 8px 20px; border-radius: 40px; color: white; text-decoration: none; font-weight: 500;">👑 Admin Panel</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Form -->
   <form method="GET" class="filter-form">

    <div class="date-range">
        <div class="field">
            <label>From</label>
            <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
        </div>

        <div class="field">
            <label>To</label>
            <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
        </div>
    </div>

    <button type="submit" class="apply-btn">Apply</button>

    <?php if($use_date_range || !empty($_GET)): ?>
        <a href="dashboard.php" class="clear-btn">Clear</a>
    <?php endif; ?>

</form>

    <!-- Quick Stats -->
    <div class="quick-stats">
        <div class="pill">📅 Avg daily spend: <?= number_format($avgDailySpend,2) ?> <?= $display_currency ?></div>
        <div class="pill">🎯 Savings goal: <?= round($savings_percent,1) ?>% of income</div>
        <div class="pill">💡 Tip: <?= ($savings_percent < 15) ? "Try reducing dining out 🍔" : "Great saving habit! 🚀" ?></div>
    </div>

    <!-- Cards -->
    <div class="cards">
        <div class="card income"><h3>💰 Total Income</h3><p><?= number_format($total_income,2) ?> <?= $display_currency ?></p></div>
        <div class="card expense"><h3>💸 Total Expense</h3><p><?= number_format($total_expense,2) ?> <?= $display_currency ?></p></div>
        <div class="card balance"><h3>⚖️ Net Balance</h3><p><?= number_format($balance,2) ?> <?= $display_currency ?></p></div>
    </div>

    <!-- Reset Buttons -->
    <div class="reset-section">
        <a href="reset-data.php?type=income" class="danger-btn" data-log onclick="return confirm('Reset all income?')">⟳ Reset Income</a>
        <a href="reset-data.php?type=expense" class="danger-btn" data-log onclick="return confirm('Reset all expenses?')">⟳ Reset Expenses</a>
        <a href="reset-data.php?type=all" class="danger-btn" data-log onclick="return confirm('⚠️ Delete all financial data?')">⚠️ Reset All</a>
    </div>

    <!-- AI Insights -->
    <h2 class="section-title">🧠 AI Insights</h2>
    <div class="cards">
        <div class="card"><h3>📈 Financial Health</h3><p><?= $savings_percent>=30?"Excellent 🔥":($savings_percent>=15?"Good 🙂":"Needs Control ⚠️") ?></p><small><?=round($savings_percent,1)?>% savings rate</small></div>
        <div class="card"><h3>📉 Spending Trend</h3><p><?= (!$use_date_range && $trend!=0) ? ($trend>0?"↑ +".round($trend,1):"↓ ".round(abs($trend),1))."%" : "N/A" ?></p><small><?= $use_date_range ? "Custom range" : "vs last month" ?></small></div>
        <div class="card"><h3>🏷️ Top Category</h3><p><?= htmlspecialchars($top_category['category']??'N/A') ?></p><small>highest expense area</small></div>
        <div class="card"><h3>📌 Monthly Status</h3><p><?= $balance>0?"Surplus 🟢":($balance<0?"Deficit 🔴":"Neutral ⚪") ?></p><small>income vs expense</small></div>
    </div>

    <!-- Recent Income -->
    <h2 class="section-title">💵 Recent Income</h2>
    <?php if($incomes): foreach($incomes as $inc): ?>
        <div class="transaction income-item">
            <div><span>➕ <?=htmlspecialchars($inc['title'])?></span><small><br><?=date('d M Y, h:i A',strtotime($inc['transaction_date']))?></small></div>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                <b>+ <?=number_format($inc['amount_pkr'],2)?> <?=htmlspecialchars($inc['currency'])?></b>
                <a href="edit-income.php?id=<?=$inc['id']?>" data-log style="color:#0f766e; text-decoration:none;">✏️</a>
                <a href="delete-income.php?id=<?=$inc['id']?>" data-log onclick="return confirm('Delete this income?')" style="color:#ef4444; text-decoration:none;">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No income records for selected period.</p><?php endif; ?>

    <!-- Recent Expenses -->
    <h2 class="section-title">💸 Recent Expenses</h2>
    <?php if($expenses): foreach($expenses as $exp): ?>
        <div class="transaction expense-item">
            <div><span>➖ <?=htmlspecialchars($exp['title'])?> (<?=htmlspecialchars($exp['category'])?>)</span><small><br><?=date('d M Y, h:i A',strtotime($exp['transaction_date']))?></small></div>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                <b>- <?=number_format($exp['amount'],2)?> <?=htmlspecialchars($exp['preferred_currency'])?></b>
                <a href="edit-expense.php?id=<?=$exp['id']?>" data-log style="color:#0f766e; text-decoration:none;">✏️</a>
                <a href="delete-expense.php?id=<?=$exp['id']?>" data-log onclick="return confirm('Delete this expense?')" style="color:#ef4444; text-decoration:none;">🗑️</a>
            </div>
        </div>
    <?php endforeach; else: ?><p>No expense records for selected period.</p><?php endif; ?>

    <!-- Charts -->
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

    // Doughnut Chart
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

    // Bar Chart
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

    // Pie Chart
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
        document.getElementById('categoryPieChart').parentElement.innerHTML = '<div style="text-align:center; padding:1rem;">📭 No category data</div>';
    }

    // Mobile sidebar toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    }
    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
    }
    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    if (window.innerWidth >= 768) closeSidebar();
</script>
</body>
</html>