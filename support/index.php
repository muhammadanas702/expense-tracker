<?php
require_once __DIR__ . '/../modules/support/boot.php';
$tickets = support_user_list($conn, $user_id);
$old = $_SESSION['support_old'] ?? ['subject' => '', 'category' => 'other', 'body' => ''];
unset($_SESSION['support_old']);

ef_page_start(['title' => 'Help & support', 'sub' => 'Ask the ExpenseFlow team', 'mode' => 'p', 'active' => 'pf', 'user' => $user]);
?>
<div class="g card" style="margin-bottom:14px">
  <div class="t"><?= ef_icon('lifebuoy') ?> New request</div>
  <form method="post" action="<?= e(ef_url('support/action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <div class="fl"><label for="subject">Subject</label><input class="inp" id="subject" name="subject" maxlength="<?= SUPPORT_SUBJECT_MAX ?>" value="<?= e($old['subject']) ?>" required></div>
    <div class="fl"><label for="category">About</label>
      <select class="inp" id="category" name="category"><?php foreach (SUPPORT_CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $old['category'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="fl"><label for="body">Message</label><textarea class="inp" id="body" name="body" rows="5" maxlength="<?= SUPPORT_BODY_MAX ?>" required><?= e($old['body']) ?></textarea>
      <small class="k">Never write your password here. We will never ask for it.</small></div>
    <button class="btn" type="submit">Send request</button>
  </form>
</div>

<div class="g card">
  <div class="t">Your requests (<?= count($tickets) ?>)</div>
  <?php foreach ($tickets as $t): ?>
  <a class="row" href="<?= e(ef_url('support/ticket.php?id=' . (int) $t['id'])) ?>" style="text-decoration:none;color:inherit">
    <span><?= e($t['subject']) ?><small><?= e(SUPPORT_CATEGORIES[$t['category']] ?? 'Other') ?> · <?= e(support_ago((string) $t['updated_at'])) ?></small></span>
    <b><?= support_pill($t['status']) ?></b>
  </a>
  <?php endforeach; ?>
  <?php if (!$tickets): ?><p class="k" style="padding:12px 0">No requests yet. If something is wrong or unclear, write to us above.</p><?php endif; ?>
</div>
<?php ef_page_end(['mode' => 'p', 'active' => 'pf']);
