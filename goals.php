<?php
/** Savings goals page. Logic lives in modules/goals/lib.php. */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/layout.php';
secure_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/modules/shared/lib.php';
if (!isset(ef_modules()['goals']) || !function_exists('goals_list')) {
    http_response_code(404);
    exit('Goals are switched off.');
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
goals_ensure_schema($conn);

$error = '';
$loadError = '';
$is_admin_user = !empty($user['is_admin']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $do = (string) ($_POST['do'] ?? 'create');
    $gid = (int) ($_POST['id'] ?? 0);
    $go = function (string $m) use ($base_url) {
        $_SESSION['success'] = $m;
        header('Location: ' . $base_url . '/goals.php');
        exit();
    };
    if ($do === 'delete') {
        goals_delete($conn, $uid, $gid);
        $go('Goal removed.');
    } elseif ($do === 'add' || $do === 'take') {
        $error = (string) goals_move($conn, $uid, $gid, (string) ($_POST['amount'] ?? ''), $do === 'add' ? 1 : -1);
        if ($error === '') {
            $g = array_values(array_filter(goals_list($conn, $uid), fn($x) => $x['id'] === $gid))[0] ?? null;
            $go($do === 'add' ? ($g && $g['done'] ? 'Goal reached — congratulations!' : 'Money added to your goal.') : 'Money taken out of your goal.');
        }
    } else {
        [$v, $err] = goals_validate($_POST);
        if ($v && !goals_create($conn, $uid, $v)) {
            $err = t('You can keep up to %s goals.', (string) GOALS_MAX_PER_USER);
        }
        if ($err === null) {
            $go('Goal created.');
        }
        $error = $err;
    }
}

try {
    $goals = goals_list($conn, $uid);
} catch (Throwable $e) {
    error_log('ExpenseFlow goals page: ' . $e->getMessage());
    $goals = [];
    $loadError = $is_admin_user ? $e->getMessage() : 'unavailable';
}
$fmt = fn(float $n) => number_format($n, 2);
ef_page_start(['title' => 'Savings goals', 'sub' => 'Save for something that matters', 'mode' => 'p', 'active' => 'goal', 'user' => $user]);
?>
    <div class="g" style="max-width:720px;margin:0 auto;padding:6px 0">
      <?php if ($loadError !== ''): ?><div class="msg err" role="alert"><?= e(t('Goals could not load right now. Please try again in a moment.')) ?><?= $loadError !== 'unavailable' ? ' (Admin detail: ' . e($loadError) . ')' : '' ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if (!$goals): ?>
        <div class="card"><p style="color:var(--mu);margin:0"><?= e(t('No goals yet. Create one below: a name, how much you need and (optionally) by when. Add money whenever you set some aside and watch the bar fill.')) ?></p></div>
      <?php endif; ?>
      <?php foreach ($goals as $g): $w = min(100, $g['pct']); $col = $g['done'] ? '#0d9f6e' : ($g['overdue'] ? '#dc2626' : null); ?>
        <div class="card goal-card" data-done="<?= $g['done'] ? '1' : '0' ?>" style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
            <b><?= e($g['name']) ?></b>
            <span style="font-weight:700;<?= $col ? 'color:' . e($col) : '' ?>"><?= $g['done'] ? e(t('Reached')) . ' ✓' : e((string) floor($g['pct'])) . '%' ?></span>
          </div>
          <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $w ?>"><i style="width:<?= e((string) $w) ?>%;<?= $col ? 'background:' . e($col) : '' ?>"></i></div>
          <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px;font-size:.85rem;color:var(--mu);flex-wrap:wrap">
            <span><?= e($g['currency']) ?> <?= e($fmt($g['saved'])) ?> <?= e(t('of')) ?> <?= e($fmt($g['target'])) ?></span>
            <span><?= $g['done'] ? e(t('Goal complete')) : e($fmt($g['left'])) . ' ' . e(t('to go')) ?></span>
          </div>
          <?php if (!$g['done'] && $g['deadline']): ?>
            <div class="goal-hint" style="margin-top:6px;font-size:.85rem;color:<?= $g['overdue'] ? '#dc2626' : 'var(--mu)' ?>">
              <?php if ($g['overdue']): ?><?= e(t('Deadline %s has passed.', date('d M Y', strtotime($g['deadline'])))) ?>
              <?php else: ?><?= e(t('By %s', date('d M Y', strtotime($g['deadline'])))) ?> · <?= e(t('save about')) ?> <b><?= e($g['currency']) ?> <?= e($fmt((float) $g['per_month'])) ?></b> <?= e(t('a month')) ?><?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <input class="inp" type="number" inputmode="decimal" step="0.01" min="0.01" name="amount" placeholder="<?= e(t('Amount')) ?>" aria-label="<?= e(t('Amount for %s', (string) $g['name'])) ?>" style="flex:1;min-width:110px" required>
            <button class="btn sm" type="submit" name="do" value="add"><?= e(t('Add')) ?></button>
            <button class="btn o sm" type="submit" name="do" value="take"><?= e(t('Take out')) ?></button>
          </form>
          <form method="POST" style="margin-top:8px" onsubmit="return confirm(<?= e(json_encode(t('Remove this goal and its history?'))) ?>)">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <button class="btn o sm" type="submit"><?= e(t('Remove goal')) ?></button>
          </form>
        </div>
      <?php endforeach; ?>
      <div class="card">
        <div class="t"><?= e(t('New goal')) ?></div>
        <form method="POST" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="do" value="create">
          <div class="fl"><label for="gname"><?= e(t('Name')) ?></label>
            <input class="inp" id="gname" type="text" name="name" maxlength="60" placeholder="<?= e(t('e.g. New laptop, Emergency fund')) ?>" required></div>
          <div class="fl"><label for="gtarget"><?= e(t('Target amount')) ?></label>
            <input class="inp amt" id="gtarget" type="number" inputmode="decimal" step="0.01" min="0.01" name="target" placeholder="<?= e(t('e.g. 100000')) ?>" required></div>
          <div class="fl"><label for="gcur"><?= e(t('Currency')) ?></label>
            <select class="inp" id="gcur" name="currency" required><?= currency_select_options('PKR') ?></select></div>
          <div class="fl"><label for="gdl"><?= e(t('Deadline')) ?> <span style="text-transform:none;font-weight:500">(<?= e(t('optional')) ?>)</span></label>
            <input class="inp" id="gdl" type="date" name="deadline" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></div>
          <button class="btn" type="submit" style="width:100%;margin-top:10px;padding:14px"><?= e(t('Create goal')) ?></button>
        </form>
      </div>
    </div>
<?php ef_page_end(['active' => 'goal']);
