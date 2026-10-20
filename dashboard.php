<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/CurrencyConverter.php';
require_once __DIR__ . '/includes/layout.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: " . $base_url . "/auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("SELECT name, is_admin FROM users WHERE id = ?");
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
require_once __DIR__ . '/modules/profile/lib.php';
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

$stmt = $conn->prepare("SELECT base_currency FROM user_monthly_currency WHERE user_id = ? AND `year_month` = ?");
$stmt->execute([$user_id, $year_month]);
$base_currency = normalize_currency($stmt->fetchColumn() ?: '');

if ($base_currency !== null && !$use_date_range) {
    $display_currency = $base_currency;
} else {
    // Custom range, or no base currency picked for this month yet: use the most recent one, else PKR.
    $stmt = $conn->prepare("SELECT base_currency FROM user_monthly_currency WHERE user_id = ? ORDER BY `year_month` DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $display_currency = normalize_currency($stmt->fetchColumn() ?: '') ?? 'PKR';
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
$stmt = $conn->prepare("SELECT id, title, $incomeColumns, transaction_date, payment_method, note FROM income WHERE user_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 5");
$stmt->execute([$user_id]);
$incomes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT id, title, amount, currency, category, transaction_date, payment_method, note FROM expenses WHERE user_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 5");
$stmt->execute([$user_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$settings = profile_settings($conn, $user_id);
$needs_setup = profile_needs_setup($conn, $user_id);
$goal = (int) $settings['savings_goal'];
$csrf = csrf_token();
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Repeating items still active (shown in their own card, can be stopped).
$stmt = $conn->prepare("SELECT id, kind, title, amount, currency, day_of_month, next_run FROM recurring_items WHERE user_id = ? AND active = 1 ORDER BY next_run");
$stmt->execute([$user_id]);
$repeating = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- PERIOD CHIPS ---------- */
$today = new DateTimeImmutable('today');
$lastMonth = $today->modify('first day of last month');
$presets = [
    'This month' => [],
    'Last month' => ['month' => (int) $lastMonth->format('n'), 'year' => (int) $lastMonth->format('Y')],
    'Last 3 months' => ['from_date' => $today->modify('first day of -2 months')->format('Y-m-d'), 'to_date' => $today->format('Y-m-d')],
    'This year' => ['from_date' => $today->format('Y') . '-01-01', 'to_date' => $today->format('Y-m-d')],
];
$activePreset = 'Custom';
foreach ($presets as $label => $q) {
    if (!$q && empty($_GET)) { $activePreset = $label; }
    elseif (isset($q['month']) && !$use_date_range && !empty($_GET) && $month === $q['month'] && $year === $q['year']) { $activePreset = $label; }
    elseif (isset($q['from_date']) && $use_date_range && $from_date === $q['from_date'] && $to_date === $q['to_date']) { $activePreset = $label; }
}
if (!$use_date_range && !empty($_GET) && $month === (int) date('n') && $year === (int) date('Y')) { $activePreset = 'This month'; }

$catIcons = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book'];
$fmt = static fn(float $n): string => number_format($n, 2);
$tip = $savings_percent < $goal ? 'Below your ' . $goal . '% goal: try cutting one regular expense.' : 'You reached your savings goal, keep it up!';

ef_page_start([
    'title' => 'Welcome back, ' . (string) ($_SESSION['user_name'] ?? $user['name']),
    'sub' => $use_date_range ? "$from_date to $to_date · $display_currency" : $period_start->format('F Y') . " · base $display_currency",
    'active' => 'dash',
    'user' => $user,
    'head' => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>',
]);
?>
<?php if ($needs_setup): ?>
<div class="g card" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><span><b>Welcome! Finish your quick setup</b><div class="k">Pick your main currency and savings goal (20 seconds).</div></span><a class="btn" style="margin-left:auto" href="<?= e($base_url) ?>/modules/profile/setup.php">Start</a></div>
<?php endif; ?>
<div class="chips" style="margin-bottom:2px">
<?php foreach ($presets as $label => $q): ?>
    <a class="chip<?= $activePreset === $label ? ' on' : '' ?>" href="<?= e($base_url . '/dashboard.php' . ($q ? '?' . http_build_query($q) : '')) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
    <button type="button" class="chip<?= $activePreset === 'Custom' ? ' on' : '' ?>" id="custom-toggle" aria-expanded="<?= $activePreset === 'Custom' ? 'true' : 'false' ?>" aria-controls="custom-range"><?= ef_icon('cal') ?>Custom</button>
</div>
<?php /* The date fields sit outside .chips on purpose: .chips hides every input inside it (for the radio chips). */ ?>
<form method="GET" id="custom-range" class="g card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end"<?= $activePreset === 'Custom' ? '' : ' hidden' ?>>
    <div class="fl" style="margin:0"><label for="cr-from">From</label><input class="inp" id="cr-from" type="date" name="from_date" value="<?= e($from_date) ?>" required></div>
    <div class="fl" style="margin:0"><label for="cr-to">To</label><input class="inp" id="cr-to" type="date" name="to_date" value="<?= e($to_date) ?>" required></div>
    <button class="btn" type="submit">Apply</button>
</form>

<div class="pills">
    <div class="pl g"><?= ef_icon('cal') ?>Avg daily spend <b><?= $fmt((float) $avgDailySpend) ?> <?= e($display_currency) ?></b></div>
    <div class="pl g"><?= ef_icon('tgt') ?>Savings rate <b><?= round($savings_percent, 1) ?>%</b> of <?= $goal ?>% goal</div>
    <div class="pl g"><?= ef_icon('bulb', '#d97706') ?><?= e($tip) ?></div>
</div>

<div class="G3">
    <div class="g card hero"><div class="k">Net balance</div><div class="v" data-countup><?= $balance < 0 ? '−' : '' ?> <?= $fmt(abs($balance)) ?> <?= e($display_currency) ?></div><span class="badge"><?= $balance > 0 ? 'Surplus this period' : ($balance < 0 ? 'Deficit this period' : 'Break-even') ?></span></div>
    <div class="g card"><div class="k">Total income</div><div class="v in"><?= $fmt($total_income) ?> <?= e($display_currency) ?></div></div>
    <div class="g card"><div class="k">Total expense</div><div class="v ex"><?= $fmt($total_expense) ?> <?= e($display_currency) ?></div></div>
</div>

<div class="G2">
    <div class="g card">
        <div class="t">Where your money goes</div>
        <?php if ($categories): ?><div style="max-width:340px;margin:0 auto"><canvas id="categoryChart" aria-label="Expenses by category"></canvas></div>
        <?php else: ?><p class="k" style="padding:20px 0;text-align:center">No expenses in this period.</p><?php endif; ?>
    </div>
    <div class="g card">
        <div class="t">Smart insights</div>
        <div class="ig">
            <div class="ins"><b><?= $savings_percent >= 30 ? 'Excellent' : ($savings_percent >= 15 ? 'Good' : 'Needs control') ?></b>Financial health · <?= round($savings_percent, 1) ?>% saved</div>
            <div class="ins"><b><?= (!$use_date_range && $trend != 0) ? ($trend > 0 ? '↑ +' . round($trend, 1) : '↓ ' . round(abs($trend), 1)) . '%' : 'N/A' ?></b><?= $use_date_range ? 'Custom range' : 'Spending vs last month' ?></div>
            <div class="ins"><b><?= e($top_category['category'] ?? 'N/A') ?></b>Top expense category</div>
            <div class="ins"><b><?= $balance > 0 ? 'Surplus' : ($balance < 0 ? 'Deficit' : 'Neutral') ?></b>Income vs expense</div>
        </div>
        <canvas id="flowChart" height="150" style="margin-top:14px" aria-label="Income versus expense"></canvas>
    </div>
</div>

<div class="G2">
    <div class="g card">
        <div class="t">Recent activity <a href="<?= e($base_url) ?>/reports/view-report.php">Full report →</a></div>
        <?php
        $all = [];
        foreach ($incomes as $r) { $r['kind'] = 'income'; $all[] = $r; }
        foreach ($expenses as $r) { $r['kind'] = 'expense'; $all[] = $r; }
        usort($all, static fn($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']));
        foreach (array_slice($all, 0, 8) as $r):
            $isInc = $r['kind'] === 'income';
            $icon = $isInc ? 'inc' : ($catIcons[$r['category'] ?? ''] ?? 'more');
            $meta = date('d M Y', strtotime($r['transaction_date'])) . (!$isInc ? ' · ' . $r['category'] : '') . (!empty($r['payment_method']) ? ' · ' . $r['payment_method'] : '');
        ?>
        <div class="row">
            <i class="cat"><?= ef_icon($icon, $isInc ? '#0d9f6e' : '#ea580c') ?></i>
            <span><?= e($r['title']) ?><small><?= e($meta) ?><?= !empty($r['note']) ? ' · ' . e($r['note']) : '' ?></small></span>
            <b class="<?= $isInc ? 'in' : 'ex' ?>"><?= $isInc ? '+' : '−' ?> <?= $fmt((float) $r['amount']) ?> <?= e($r['currency']) ?></b>
            <span class="acts">
                <a href="<?= e($base_url) ?>/<?= $isInc ? 'edit-income' : 'edit-expense' ?>.php?id=<?= (int) $r['id'] ?>" aria-label="Edit"><?= ef_icon('pen') ?></a>
                <a class="del" href="<?= e($base_url) ?>/<?= $isInc ? 'delete-income' : 'delete-expense' ?>.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"id":<?= (int) $r['id'] ?>}' onclick="return confirm('Delete this entry?')" aria-label="Delete"><?= ef_icon('tr') ?></a>
            </span>
        </div>
        <?php endforeach; if (!$all): ?><p class="k" style="padding:16px 0">Nothing yet. Add your first income or expense.</p><?php endif; ?>
    </div>

    <div style="display:flex;flex-direction:column;gap:14px">
        <div class="g card">
            <div class="t">Repeating every month</div>
            <?php foreach ($repeating as $r): ?>
            <div class="row">
                <i class="cat"><?= ef_icon('rpt') ?></i>
                <span><?= e($r['title']) ?><small>Next: <?= e(date('d M Y', strtotime($r['next_run']))) ?> · <?= e($r['kind']) ?></small></span>
                <b><?= $fmt((float) $r['amount']) ?> <?= e($r['currency']) ?></b>
                <span class="acts"><a class="del" href="<?= e($base_url) ?>/modules/personal/stop-recurring.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"id":<?= (int) $r['id'] ?>}' onclick="return confirm('Stop repeating this item?')" aria-label="Stop repeating"><?= ef_icon('tr') ?></a></span>
            </div>
            <?php endforeach; if (!$repeating): ?><p class="k">None. Turn on “Repeat every month” when adding an entry.</p><?php endif; ?>
        </div>
        <?php if (!$use_date_range): ?>
        <div class="g card" id="base">
            <div class="t">Base currency for <?= e($period_start->format('F Y')) ?></div>
            <form method="POST" action="<?= e($base_url) ?>/change-base-currency.php" style="display:flex;gap:10px;flex-wrap:wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="year_month" value="<?= e($year_month) ?>">
                <select class="inp" name="new_base_currency" style="flex:1;min-width:150px" aria-label="Base currency"><?= currency_select_options($display_currency) ?></select>
                <button class="btn" type="submit">Change</button>
            </form>
        </div>
        <?php endif; ?>
        <div class="g card"><div class="row" style="border:0;padding:0"><span>Reset data or delete account<small>Now in Profile &amp; Settings</small></span><b><a class="lnk" href="<?= e($base_url) ?>/profile.php">Open</a></b></div></div>
    </div>
</div>
<?php
$js = '<script>
(function(){var t=document.getElementById("custom-toggle"),f=document.getElementById("custom-range");if(t&&f)t.addEventListener("click",function(){f.hidden=!f.hidden;t.setAttribute("aria-expanded",f.hidden?"false":"true");if(!f.hidden)document.getElementById("cr-from").focus()})})();
(function(){
  if (typeof Chart === "undefined") return;
  var cats = ' . json_encode($categories, $json_flags) . ', vals = ' . json_encode($categoryTotals, $json_flags) . ';
  var inc = ' . json_encode($total_income, $json_flags) . ', exp = ' . json_encode($total_expense, $json_flags) . ';
  var palette = ["#0f766e","#1d4ed8","#F59E0B","#f97316","#4DA8DA","#a855f7","#ef4444","#64748b"];
  var c1 = document.getElementById("categoryChart");
  if (c1) new Chart(c1, {type:"doughnut", data:{labels:cats, datasets:[{data:vals, backgroundColor:palette, borderWidth:0}]},
    options:{cutout:"62%", plugins:{legend:{position:"bottom"}, tooltip:{callbacks:{label:function(c){return " "+c.label+": "+Number(c.raw).toFixed(2)}}}}}});
  var c2 = document.getElementById("flowChart");
  if (c2) new Chart(c2, {type:"bar", data:{labels:["Income","Expense"], datasets:[{data:[inc,exp], backgroundColor:["#10b981","#f97316"], borderRadius:8}]},
    options:{plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}});
})();
setTimeout(function(){document.querySelectorAll("[data-flash]").forEach(function(m){m.style.transition="opacity .5s";m.style.opacity="0";setTimeout(function(){m.remove()},500)})},4000);
</script>';
ef_page_end(['active' => 'dash', 'scripts' => $js]);
