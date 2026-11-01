<?php
/** Quick add: paste a payment SMS, get the Add form pre-filled. Nothing is stored. Logic in modules/quickadd/lib.php. */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/layout.php';
secure_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules.php';
if (!isset(ef_modules()['quickadd']) || !is_file(__DIR__ . '/modules/quickadd/lib.php')) {
    http_response_code(404);
    exit('Quick add is switched off.');
}
require_once __DIR__ . '/modules/quickadd/lib.php';
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
$sms = '';
$res = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $sms = is_string($_POST['sms'] ?? null) ? mb_substr((string) $_POST['sms'], 0, QUICKADD_MAX_LENGTH) : '';
        $res = quickadd_parse($sms);
        if (!$res['ok']) {
            $error = $sms === '' ? 'Paste the message first.' : 'I could not find an amount in that message. You can still add it by hand.';
            $res = null;
        }
    }
}
ef_page_start(['title' => 'Quick add', 'sub' => 'Paste a bank or wallet SMS', 'mode' => 'p', 'active' => 'qadd', 'user' => $user]);
?>
    <div class="g" style="max-width:640px;margin:0 auto;padding:6px 0">
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?> <a href="<?= e($base_url) ?>/expense/add-expense.php">Add by hand</a></div><?php endif; ?>
      <div class="card">
        <form method="POST" autocomplete="off">
          <?= csrf_field() ?>
          <div class="fl"><label for="sms">Message</label>
            <textarea class="inp" id="sms" name="sms" rows="5" maxlength="<?= (int) QUICKADD_MAX_LENGTH ?>" placeholder="e.g. Rs. 1,500.00 debited from A/C 1234 at FOODPANDA on 01-11-26" required><?= e($sms) ?></textarea></div>
          <button class="btn" type="submit" style="width:100%;margin-top:6px;padding:14px">Read message</button>
          <p style="color:var(--mu);font-size:.82rem;margin-top:8px">The message is only read to fill the form: it is not saved anywhere. You can check and change everything before saving.</p>
        </form>
      </div>
      <?php if ($res): ?>
      <div class="card" id="qa-result" style="margin-top:14px" data-type="<?= e((string) $res['type']) ?>">
        <div class="t">I found</div>
        <div class="row"><span>Amount</span><b><?= e($res['currency']) ?> <?= e(number_format((float) $res['amount'], 2)) ?></b></div>
        <div class="row"><span>Looks like</span><b><?= $res['type'] === 'income' ? 'Income (money received)' : ($res['type'] === 'expense' ? 'Expense (money spent)' : 'Not sure') ?></b></div>
        <div class="row"><span>Title</span><b><?= e($res['title']) ?></b></div>
        <?php if ($res['category'] !== ''): ?><div class="row"><span>Category guess</span><b><?= e($res['category']) ?></b></div><?php endif; ?>
        <?php if ($res['type'] === null): ?>
          <div style="display:flex;gap:10px;margin-top:12px">
            <a class="btn" style="flex:1;text-align:center" href="<?= e(quickadd_form_url($base_url, $res + ['type' => 'expense'])) ?>">Save as expense</a>
            <a class="btn o" style="flex:1;text-align:center" href="<?= e(quickadd_form_url($base_url, $res + ['type' => 'income'])) ?>">Save as income</a>
          </div>
        <?php else: ?>
          <a class="btn" style="display:block;text-align:center;margin-top:12px;padding:14px" href="<?= e(quickadd_form_url($base_url, $res)) ?>">Review and save</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
<?php ef_page_end(['active' => 'qadd']);
