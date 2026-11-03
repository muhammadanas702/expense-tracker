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
$prefs = quickadd_prefs($conn, $uid);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qa_action'])) {
    $act = (string) $_POST['qa_action'];
    $rid = (int) ($_POST['id'] ?? 0);
    if (!csrf_valid()) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
    } else {
        try {
            if ($act === 'auto_on' || $act === 'auto_off') {
                quickadd_set_auto($conn, $uid, $act === 'auto_on');
                $_SESSION['success'] = $act === 'auto_on' ? 'Auto-add is on. New payment messages are added from now on.' : 'Auto-add is off.';
            } elseif ($act === 'review_add') {
                $_SESSION['success'] = quickadd_review_resolve($conn, $uid, $rid, 'add', (string) ($_POST['kind'] ?? '')) ? 'Added.' : 'That message was already handled.';
            } elseif ($act === 'review_ignore') {
                $_SESSION['success'] = quickadd_review_resolve($conn, $uid, $rid, 'ignore', '', ($_POST['always'] ?? '') === '1') ? 'Ignored.' : 'That message was already handled.';
            } elseif ($act === 'undo') {
                $_SESSION['success'] = quickadd_undo($conn, $uid, $rid) ? 'Removed. That message will not be added again.' : 'Nothing to undo.';
            }
        } catch (Throwable $e) {
            error_log('ExpenseFlow quick add action: ' . $e->getMessage());
            $_SESSION['error'] = 'Something went wrong. Please try again.';
        }
    }
    header('Location: ' . $base_url . '/quick-add.php');
    exit();
}
$review = [];
$recent = [];
try {
    $review = quickadd_review_list($conn, $uid);
    $recent = quickadd_recent_added($conn, $uid);
} catch (Throwable $e) {
    error_log('ExpenseFlow quick add lists: ' . $e->getMessage());
}
$reasons = ['direction' => 'Could not tell if money came in or went out', 'mixed' => 'Mentions both money in and out', 'person' => 'From a phone number, not a bank'];
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
      <div class="card" id="sms-device" hidden style="margin-bottom:14px">
        <div class="t"><?= ef_icon('phone') ?> Read my messages</div>
        <p style="color:var(--mu);font-size:.9rem;margin-top:0">Let ExpenseFlow look through the SMS on this phone for payments. Android asks you first. The messages stay on your phone: only the one you tap is read by the form below.</p>
        <button class="btn" type="button" id="sms-read" style="width:100%;padding:12px">Allow and read</button>
        <p id="sms-info" style="color:var(--mu);font-size:.85rem;margin:8px 0 0" role="status"></p>
        <div id="sms-list"></div>
        <div id="sms-auto" style="border-top:1px solid rgba(91,107,128,.15);margin-top:12px;padding-top:12px" data-on="<?= $prefs['auto'] ? '1' : '0' ?>">
          <b><?= ef_icon('spark') ?> Add payments automatically</b>
          <p style="color:var(--mu);font-size:.88rem;margin:6px 0 10px">When you open the app, new bank and wallet messages are read and added as income or expense by themselves. One-time codes, offers, failed payments, reminders and balance alerts are ignored; unclear ones wait below for your check. Only payment details are sent to your account, you can undo any entry, and old messages are never imported. With the latest Android app it also works while the app is closed (this phone gets its own key, which stops working when you turn auto-add off).</p>
          <p id="sms-bg" style="color:var(--mu);font-size:.85rem;margin:-4px 0 10px" role="status"></p>
          <form method="POST"><?= csrf_field() ?>
            <?php if ($prefs['auto']): ?>
              <button class="btn o" type="submit" name="qa_action" value="auto_off" style="width:100%">Turn auto-add off</button>
            <?php else: ?>
              <button class="btn" type="submit" name="qa_action" value="auto_on" id="auto-on" style="width:100%">Turn auto-add on</button>
            <?php endif; ?>
          </form>
        </div>
      </div>
      <?php if ($review): ?>
      <div class="card" id="qa-review" style="margin-bottom:14px">
        <div class="t">Needs your check (<?= count($review) ?>)</div>
        <?php foreach ($review as $r): ?>
        <div class="row" style="display:block;border-top:1px solid rgba(91,107,128,.15);padding:10px 0">
          <div style="font-size:.88rem"><?= e((string) $r['body']) ?></div>
          <small style="color:var(--mu)"><?= e($reasons[$r['reason']] ?? 'Not sure') ?> · <?= e($r['currency']) ?> <?= e(number_format((float) $r['amount'], 2)) ?> · <?= e((string) $r['title']) ?></small>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn sm" type="submit" name="qa_action" value="review_add" onclick="this.form.kind.value='expense'">Expense</button>
            <button class="btn sm" type="submit" name="qa_action" value="review_add" onclick="this.form.kind.value='income'">Income</button>
            <button class="btn sm o" type="submit" name="qa_action" value="review_ignore">Ignore</button>
            <label style="font-size:.8rem;color:var(--mu);align-self:center"><input type="checkbox" name="always" value="1"> always ignore this sender</label>
            <input type="hidden" name="kind" value="">
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($recent): ?>
      <div class="card" id="qa-recent" style="margin-bottom:14px">
        <div class="t">Added from SMS</div>
        <?php foreach ($recent as $r): ?>
        <div class="row" style="display:flex;justify-content:space-between;gap:8px;align-items:center;border-top:1px solid rgba(91,107,128,.15);padding:8px 0">
          <span><?= e((string) $r['title']) ?><small style="display:block;color:var(--mu)"><?= $r['tx_kind'] === 'income' ? 'Income' : 'Expense' ?><?= $r['category'] !== '' ? ' · ' . e((string) $r['category']) : '' ?> · <?= e($r['currency']) ?> <?= e(number_format((float) $r['amount'], 2)) ?></small></span>
          <form method="POST"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm o" type="submit" name="qa_action" value="undo">Undo</button></form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
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
<?php ef_page_end(['active' => 'qadd', 'scripts' => '<script src="' . e(ef_asset('assets/quick-add.js')) . '" defer></script>']);
