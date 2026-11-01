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
            $err = 'You can keep up to ' . GOALS_MAX_PER_USER . ' goals.';
        }
        if ($err === null) {
            $go('Goal created.');
        }
        $error = $err;
    }
}

$goals = goals_list($conn, $uid);
$fmt = fn(float $n) => number_format($n, 2);
ef_page_start(['title' => 'Savings goals', 'sub' => 'Save for something that matters', 'mode' => 'p', 'active' => 'goal', 'user' => $user]);
?>
    <div class="g" style="max-width:720px;margin:0 auto;padding:6px 0">
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if (!$goals): ?>
        <div class="card"><p style="color:var(--mu);margin:0">No goals yet. Create one below: a name, how much you need and (optionally) by when. Add money whenever you set some aside and watch the bar fill.</p></div>
      <?php endif; ?>
      <?php foreach ($goals as $g): $w = min(100, $g['pct']); $col = $g['done'] ? '#0d9f6e' : ($g['overdue'] ? '#dc2626' : null); ?>
        <div class="card goal-card" data-done="<?= $g['done'] ? '1' : '0' ?>" style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline">
            <b><?= e($g['name']) ?></b>
            <span style="font-weight:700;<?= $col ? 'color:' . e($col) : '' ?>"><?= $g['done'] ? 'Reached ✓' : e((string) floor($g['pct'])) . '%' ?></span>
          </div>
          <div class="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $w ?>"><i style="width:<?= e((string) $w) ?>%;<?= $col ? 'background:' . e($col) : '' ?>"></i></div>
          <div style="display:flex;justify-content:space-between;gap:10px;margin-top:6px;font-size:.85rem;color:var(--mu);flex-wrap:wrap">
            <span><?= e($g['currency']) ?> <?= e($fmt($g['saved'])) ?> of <?= e($fmt($g['target'])) ?></span>
            <span><?= $g['done'] ? 'Goal complete' : e($fmt($g['left'])) . ' to go' ?></span>
          </div>
          <?php if (!$g['done'] && $g['deadline']): ?>
            <div class="goal-hint" style="margin-top:6px;font-size:.85rem;color:<?= $g['overdue'] ? '#dc2626' : 'var(--mu)' ?>">
              <?php if ($g['overdue']): ?>Deadline <?= e(date('d M Y', strtotime($g['deadline']))) ?> has passed.
              <?php else: ?>By <?= e(date('d M Y', strtotime($g['deadline']))) ?> · save about <b><?= e($g['currency']) ?> <?= e($fmt((float) $g['per_month'])) ?></b> a month<?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <input class="inp" type="number" inputmode="decimal" step="0.01" min="0.01" name="amount" placeholder="Amount" aria-label="Amount for <?= e($g['name']) ?>" style="flex:1;min-width:110px" required>
            <button class="btn sm" type="submit" name="do" value="add">Add</button>
            <button class="btn o sm" type="submit" name="do" value="take">Take out</button>
          </form>
          <form method="POST" style="margin-top:8px" onsubmit="return confirm('Remove this goal and its history?')">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
            <button class="btn o sm" type="submit">Remove goal</button>
          </form>
        </div>
      <?php endforeach; ?>
      <div class="card">
        <div class="t">New goal</div>
        <form method="POST" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="do" value="create">
          <div class="fl"><label for="gname">Name</label>
            <input class="inp" id="gname" type="text" name="name" maxlength="60" placeholder="e.g. New laptop, Emergency fund" required></div>
          <div class="fl"><label for="gtarget">Target amount</label>
            <input class="inp amt" id="gtarget" type="number" inputmode="decimal" step="0.01" min="0.01" name="target" placeholder="e.g. 100000" required></div>
          <div class="fl"><label for="gcur">Currency</label>
            <select class="inp" id="gcur" name="currency" required><?= currency_select_options('PKR') ?></select></div>
          <div class="fl"><label for="gdl">Deadline <span style="text-transform:none;font-weight:500">(optional)</span></label>
            <input class="inp" id="gdl" type="date" name="deadline" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>"></div>
          <button class="btn" type="submit" style="width:100%;margin-top:10px;padding:14px">Create goal</button>
        </form>
      </div>
    </div>
<?php ef_page_end(['active' => 'goal']);
