<?php
/** Reminders page: bills and payments with a due date. Logic lives in modules/reminders/lib.php. */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/layout.php';
secure_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/modules/shared/lib.php';
if (!isset(ef_modules()['reminders']) || !function_exists('reminders_list')) {
    http_response_code(404);
    exit('Reminders are switched off.');
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
$error = '';
$loadError = '';
$items = [];
try {
    reminders_ensure_schema($conn);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_post_csrf();
        $do = (string) ($_POST['do'] ?? 'create');
        $id = (int) ($_POST['id'] ?? 0);
        $go = function (string $m) use ($base_url) {
            $_SESSION['success'] = $m;
            header('Location: ' . $base_url . '/reminders.php');
            exit();
        };
        if ($do === 'delete') {
            reminders_delete($conn, $uid, $id);
            $go('Reminder removed.');
        } elseif ($do === 'paid') {
            $error = (string) reminders_mark_paid($conn, $uid, $id, !empty($_POST['record']));
            if ($error === '') {
                $go(!empty($_POST['record']) ? 'Marked as paid and saved as an expense.' : 'Marked as paid.');
            }
        } else {
            [$v, $err] = reminders_validate($_POST);
            if ($v && !reminders_create($conn, $uid, $v)) {
                $err = 'You can keep up to ' . REMINDERS_MAX_PER_USER . ' reminders.';
            }
            if ($err === null) {
                reminders_check_due($conn, $uid);
                $go('Reminder saved.');
            }
            $error = $err;
        }
    }
    reminders_check_due($conn, $uid);
    $items = reminders_list($conn, $uid);
} catch (Throwable $e) {
    error_log('ExpenseFlow reminders page: ' . $e->getMessage());
    $loadError = !empty($user['is_admin']) ? $e->getMessage() : 'unavailable';
}
$col = ['late' => '#dc2626', 'today' => '#f59e0b', 'soon' => '#f59e0b', 'later' => 'var(--mu)'];
$when = function (array $r) {
    if ($r['days'] < 0) { return 'Overdue by ' . -$r['days'] . ' day' . ($r['days'] === -1 ? '' : 's'); }
    if ($r['days'] === 0) { return 'Due today'; }
    if ($r['days'] === 1) { return 'Due tomorrow'; }
    return 'Due in ' . $r['days'] . ' days';
};
ef_page_start(['title' => 'Reminders', 'sub' => 'Never miss a bill', 'mode' => 'p', 'active' => 'rem', 'user' => $user]);
?>
    <div class="g" style="max-width:720px;margin:0 auto;padding:6px 0">
      <?php if ($loadError !== ''): ?><div class="msg err" role="alert">Reminders could not load right now. Please try again in a moment.<?= $loadError !== 'unavailable' ? ' (Admin detail: ' . e($loadError) . ')' : '' ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <div class="card">
        <div class="t"><?= ef_icon('bell') ?> Coming up</div>
        <?php if (!$items && $loadError === ''): ?><p style="color:var(--mu)">No reminders yet. Add a bill below (rent, internet, a loan instalment) and you will get a notification 3 days before it is due.</p><?php endif; ?>
        <?php foreach ($items as $r): ?>
          <div class="rem-row" data-state="<?= e($r['state']) ?>" style="padding:12px 0;border-top:1px solid rgba(91,107,128,.15)">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
              <b><?= e($r['title']) ?><?= $r['monthly'] ? ' <small style="color:var(--mu);font-weight:500">· every month</small>' : '' ?></b>
              <span style="font-weight:700;color:<?= e($col[$r['state']]) ?>"><?= e($when($r)) ?></span>
            </div>
            <div style="font-size:.85rem;color:var(--mu);margin-top:2px"><?= e(date('d M Y', strtotime($r['due_date']))) ?><?= $r['amount'] !== null ? ' · ' . e($r['currency']) . ' ' . e(number_format($r['amount'], 2)) : '' ?></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
              <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?= csrf_field() ?><input type="hidden" name="do" value="paid"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <?php if ($r['amount'] !== null): ?><label style="font-size:.85rem"><input type="checkbox" name="record" value="1"> Also save as expense</label><?php endif; ?>
                <button class="btn sm" type="submit">Paid</button>
              </form>
              <form method="POST" onsubmit="return confirm('Remove this reminder?')">
                <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn o sm" type="submit">Remove</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="card" style="margin-top:14px">
        <div class="t">New reminder</div>
        <form method="POST" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="do" value="create">
          <div class="fl"><label for="rtitle">What is it for?</label>
            <input class="inp" id="rtitle" type="text" name="title" maxlength="60" placeholder="e.g. Internet bill, Rent" required></div>
          <div class="fl"><label for="rdue">Due date</label>
            <input class="inp" id="rdue" type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>" required></div>
          <div class="fl"><label for="ramt">Amount <span style="text-transform:none;font-weight:500">(optional)</span></label>
            <input class="inp amt" id="ramt" type="number" inputmode="decimal" step="0.01" min="0.01" name="amount" placeholder="e.g. 3500"></div>
          <div class="fl"><label for="rcur">Currency</label>
            <select class="inp" id="rcur" name="currency" required><?= currency_select_options('PKR') ?></select></div>
          <label class="row" style="cursor:pointer">
            <span><?= ef_icon('rpt') ?> Repeat every month<small>After "Paid" the next date is set for you</small></span>
            <b><input class="swc" type="checkbox" name="monthly" value="1"><i class="sw"></i></b>
          </label>
          <button class="btn" type="submit" style="width:100%;margin-top:14px;padding:14px">Save reminder</button>
        </form>
      </div>
    </div>
<?php ef_page_end(['active' => 'rem']);
