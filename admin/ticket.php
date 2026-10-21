<?php
require_once __DIR__ . '/auth.php';
admin_need('support');
require_once __DIR__ . '/../modules/support/lib.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$ticket = $id ? support_ticket_get($conn, $id) : null;
if (!$ticket) {
    $_SESSION['error'] = 'Request not found.';
    header('Location: support.php');
    exit();
}
$msgs = support_messages($conn, $id);
$canView = admin_can($conn, $admin['role'], 'view') && $ticket['user_name'] !== null;

admin_page_start(['title' => $ticket['subject'], 'sub' => (SUPPORT_CATEGORIES[$ticket['category']] ?? 'Other') . ' · opened ' . support_ago((string) $ticket['created_at']), 'active' => 'sup']);
?>
<div class="g card" style="margin-bottom:14px">
  <div class="row" style="border-top:0"><span>From<small><?= e($ticket['user_email'] ?? '') ?></small></span>
    <b><?php if ($canView): ?><a class="lnk" href="view-user.php?id=<?= (int) $ticket['user_id'] ?>"><?= e($ticket['user_name']) ?></a><?php else: ?><?= e($ticket['user_name'] ?? 'Deleted user') ?><?php endif; ?></b></div>
  <div class="row"><span>Status</span><b><?= support_pill($ticket['status']) ?></b></div>
  <?php foreach ($msgs as $m): ?>
  <div class="row" style="align-items:flex-start;white-space:normal">
    <span><strong><?= $m['is_staff'] ? e((($m['role'] ?? '') === 'super' && $admin['role'] !== 'super' ? 'Super Admin' : ($m['name'] ?? 'Former staff')) . ' (staff)') : e($ticket['user_name'] ?? 'User') ?></strong><small><?= e(date('d M Y, H:i', strtotime((string) $m['created_at']))) ?></small>
      <div style="margin-top:6px;white-space:pre-wrap;overflow-wrap:anywhere"><?= e($m['body']) ?></div></span>
  </div>
  <?php endforeach; ?>
</div>

<div class="g card" id="reply">
  <form method="post" action="ticket-action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="fl"><label for="body">Your reply to the user</label><textarea class="inp" id="body" name="body" rows="4" maxlength="<?= SUPPORT_BODY_MAX ?>"></textarea>
      <small class="k">The user sees this as "ExpenseFlow support" and also gets it by email. Never ask for a password.</small></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit" name="do" value="reply">Send reply</button>
      <?php if ($ticket['status'] !== 'closed'): ?><button class="btn o" type="submit" name="do" value="close" formnovalidate>Close request</button>
      <?php else: ?><button class="btn o" type="submit" name="do" value="reopen" formnovalidate>Reopen</button><?php endif; ?>
    </div>
  </form>
</div>
<?php admin_page_end();
