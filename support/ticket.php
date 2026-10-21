<?php
require_once __DIR__ . '/../modules/support/boot.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$ticket = $id ? support_ticket_for_user($conn, $id, $user_id) : null;
if (!$ticket) {
    $_SESSION['error'] = 'Request not found.';
    support_back('support/index.php');
}
$msgs = support_messages($conn, $id);
support_mark_seen($conn, $user_id, $id);

ef_page_start(['title' => $ticket['subject'], 'sub' => (SUPPORT_CATEGORIES[$ticket['category']] ?? 'Other') . ' · opened ' . support_ago((string) $ticket['created_at']), 'neutral' => true, 'active' => 'profile', 'user' => $user]);
?>
<div class="g card" style="margin-bottom:14px">
  <div class="row" style="border-top:0"><span>Status</span><b><?= support_pill($ticket['status']) ?></b></div>
  <?php foreach ($msgs as $m): ?>
  <div class="row" style="align-items:flex-start;white-space:normal">
    <span><strong><?= $m['is_staff'] ? 'ExpenseFlow support' : 'You' ?></strong><small><?= e(support_ago((string) $m['created_at'])) ?></small>
      <div style="margin-top:6px;white-space:pre-wrap;overflow-wrap:anywhere"><?= e($m['body']) ?></div></span>
  </div>
  <?php endforeach; ?>
</div>

<div class="g card">
  <?php if ($ticket['status'] === 'closed'): ?>
    <p class="k" style="margin-bottom:10px">This request is closed. Writing a reply opens it again.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(ef_url('support/action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reply">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="fl"><label for="body">Your reply</label><textarea class="inp" id="body" name="body" rows="4" maxlength="<?= SUPPORT_BODY_MAX ?>" required></textarea></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn" type="submit">Send reply</button>
      <?php if ($ticket['status'] !== 'closed'): ?>
      <button class="btn o" type="submit" name="do" value="close" formnovalidate>Mark as solved</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php ef_page_end(['mode' => 'p', 'active' => 'pf']);
