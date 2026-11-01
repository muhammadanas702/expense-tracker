<?php
/** Budgets page: a monthly limit per category with progress bars. Logic lives in modules/budgets/lib.php. */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/layout.php';
secure_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/modules/shared/lib.php';
if (!isset(ef_modules()['budgets']) || !function_exists('budgets_status')) {
    http_response_code(404);
    exit('Budgets are switched off.');
}
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
$uid = (int) $_SESSION['user_id'];
$u = $conn->prepare('SELECT name, is_admin FROM users WHERE id = ?');
$u->execute([$uid]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
personal_ensure_schema($conn);
budgets_ensure_schema($conn);

$cats = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book', 'Other' => 'more'];
if (is_file(__DIR__ . '/modules/categories/lib.php')) {
    require_once __DIR__ . '/modules/categories/lib.php';
    $cats = categories_active($conn);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (($_POST['do'] ?? '') === 'delete') {
        budgets_delete($conn, $uid, (int) ($_POST['id'] ?? 0));
        $_SESSION['success'] = 'Budget removed.';
        header('Location: ' . $base_url . '/budgets.php');
        exit();
    }
    [$v, $err] = budgets_validate($_POST, array_keys($cats));
    if ($v && !budgets_save($conn, $uid, $v)) {
        $err = 'You can keep up to ' . BUDGET_MAX_PER_USER . ' budgets.';
    }
    if ($err === null) {
        budgets_check_alerts($conn, $uid, $v['category']);
        $_SESSION['success'] = 'Budget saved for ' . $v['category'] . '.';
        header('Location: ' . $base_url . '/budgets.php');
        exit();
    }
    $error = $err;
}

$items = budgets_status($conn, $uid);
$colors = ['ok' => '#0d9f6e', 'warn' => '#f59e0b', 'over' => '#dc2626'];
$msg = ['ok' => 'On track', 'warn' => 'Close to the limit', 'over' => 'Over budget'];
ef_page_start(['title' => 'Budgets', 'sub' => 'Monthly limits for ' . date('F Y'), 'mode' => 'p', 'active' => 'bud', 'user' => $user]);
?>
    <div class="g" style="max-width:720px;margin:0 auto;padding:6px 0">
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <div class="card">
        <div class="t"><?= ef_icon('tgt') ?> This month</div>
        <?php if (!$items): ?>
          <p style="color:var(--mu)">No budgets yet. Pick a category below and set how much you want to spend on it each month. You will get a notification at 80% and again at 100%.</p>
        <?php endif; ?>
        <?php foreach ($items as $b): $w = min(100, $b['pct']); ?>
          <div class="bud-row" data-level="<?= e($b['level']) ?>" style="padding:12px 0;border-top:1px solid rgba(91,107,128,.15)">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
              <b><?= e($b['category']) ?></b>
              <span style="color:<?= e($colors[$b['level']]) ?>;font-weight:700"><?= e((string) round($b['pct'])) ?>% · <?= e($msg[$b['level']]) ?></span>
            </div>
            <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $w ?>"><i style="width:<?= e((string) $w) ?>%;background:<?= e($colors[$b['level']]) ?>"></i></div>
            <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px;font-size:.85rem;color:var(--mu)">
              <span><?= e($b['currency']) ?> <?= e(number_format($b['spent'], 2)) ?> of <?= e(number_format($b['amount'], 2)) ?></span>
              <span><?= $b['left'] >= 0 ? e(number_format($b['left'], 2)) . ' left' : e(number_format(-$b['left'], 2)) . ' over' ?></span>
            </div>
            <form method="POST" style="margin-top:6px" onsubmit="return confirm('Remove this budget?')">
              <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
              <button class="btn o sm" type="submit">Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card" style="margin-top:14px">
        <div class="t">Set a budget</div>
        <form method="POST" autocomplete="off">
          <?= csrf_field() ?>
          <div class="fl"><label for="bcat">Category</label>
            <select class="inp" id="bcat" name="category" required>
              <?php foreach (array_keys($cats) as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
            </select></div>
          <div class="fl"><label for="bamt">Monthly limit</label>
            <input class="inp amt" id="bamt" type="number" inputmode="decimal" step="0.01" min="0.01" name="amount" placeholder="e.g. 20000" required></div>
          <div class="fl"><label for="bcur">Currency</label>
            <select class="inp" id="bcur" name="currency" required><?= currency_select_options('PKR') ?></select></div>
          <button class="btn" type="submit" style="width:100%;margin-top:10px;padding:14px">Save budget</button>
          <p style="color:var(--mu);font-size:.82rem;margin-top:8px">Setting a budget for a category that already has one changes its limit.</p>
        </form>
      </div>
    </div>
<?php ef_page_end(['active' => 'bud']);
