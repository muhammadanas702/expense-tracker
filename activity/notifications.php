<?php
require_once __DIR__ . '/../modules/activity/boot.php';
$rows = activity_notifications($conn, $user_id);
$unread = array_filter($rows, fn($r) => $r['read_at'] === null);
$firstView = array_map(fn($r) => (int) $r['nid'], $unread);
// Opening the page counts as reading; the rows below still show which ones were new.
if ($unread && !isset($_GET['peek'])) {
    activity_mark_read($conn, $user_id);
}

$supportRows = [];
if (is_file(__DIR__ . '/../modules/support/lib.php')) {
    require_once __DIR__ . '/../modules/support/lib.php';
    $supportRows = support_unseen_list($conn, $user_id);
    if ($supportRows && !isset($_GET['peek'])) {
        support_mark_seen($conn, $user_id);
    }
}
$popup = isset($_GET['popup']);
if ($popup) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<div class="np-h"><b>Notifications</b><a href="' . e(ef_url('activity/notifications.php')) . '">See all</a></div>';
    foreach ($supportRows as $t) {
        echo '<a class="np-r new" href="' . e(ef_url('support/ticket.php?id=' . (int) $t['id'])) . '"><span class="gi">' . ef_icon('bell', '#1d4ed8') . '</span><span><b>Support replied</b><small>' . e($t['subject']) . ' · ' . e(support_ago((string) $t['updated_at'])) . '</small></span></a>';
    }
    if (!$rows && !$supportRows) {
        echo '<p class="k np-e">No notifications yet. You are told when someone adds or changes an expense that involves you.</p>';
    }
    foreach (array_slice($rows, 0, 8) as $r) {
        [$icon, $text, $amt] = activity_describe($r, $user_id);
        $isNew = in_array((int) $r['nid'], $firstView, true);
        $href = !empty($r['group_id']) ? ef_url('shared/group.php?id=' . (int) $r['group_id']) : ef_url('activity/notifications.php');
        echo '<a class="np-r' . ($isNew ? ' new' : '') . '" href="' . e($href) . '"><span class="gi">' . ef_icon($icon, '#1d4ed8') . '</span><span>'
            . ($isNew ? '<b>' . e($text) . '</b>' : e($text)) . '<small>' . e($r['group_name'] ?? 'Group') . ' · ' . e(activity_ago((int) $r['age_s'], $r['created_at'])) . '</small></span>'
            . ($amt !== '' ? '<b class="am">' . e($amt) . '</b>' : '') . '</a>';
    }
    exit();
}

ef_page_start(['title' => 'Notifications', 'sub' => $unread ? count($unread) . ' new' : 'You are all caught up', 'neutral' => true, 'active' => 'notif', 'user' => $user]);
?>
<div class="g card">
  <div class="t">Notifications <a href="<?= e(ef_url('activity/index.php')) ?>">Activity feed →</a></div>
  <?php foreach ($supportRows as $t): ?>
  <a class="row" href="<?= e(ef_url('support/ticket.php?id=' . (int) $t['id'])) ?>" style="background:rgba(29,78,216,.07);border-radius:12px;padding-left:8px;padding-right:8px">
    <span class="gi" style="margin:0;width:36px;height:36px;flex:none"><?= ef_icon('bell', '#1d4ed8') ?></span>
    <span><b>Support replied</b><small><?= e($t['subject']) ?> · <?= e(support_ago((string) $t['updated_at'])) ?></small></span>
  </a>
  <?php endforeach; ?>
  <?php if (!$rows && !$supportRows): ?><p class="k" style="padding:12px 0">No notifications yet. You are told when someone adds or changes an expense that involves you, or records a payment to you.</p><?php endif; ?>
  <?php foreach ($rows as $r): [$icon, $text, $amt] = activity_describe($r, $user_id); $isNew = in_array((int) $r['nid'], $firstView, true); ?>
  <?php $href = !empty($r['group_id']) ? ef_url('shared/group.php?id=' . (int) $r['group_id']) : ''; ?>
  <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="row"<?= $isNew ? ' style="background:rgba(29,78,216,.07);border-radius:12px;padding-left:8px;padding-right:8px"' : '' ?>>
    <span class="gi" style="margin:0;width:36px;height:36px;flex:none"><?= ef_icon($icon, '#1d4ed8') ?></span>
    <span><?= $isNew ? '<b>' . e($text) . '</b>' : e($text) ?><small><?= e($r['group_name'] ?? 'Group') ?> · <?= e(activity_ago((int) $r['age_s'], $r['created_at'])) ?></small></span>
    <?php if ($amt !== ''): ?><b><?= e($amt) ?></b><?php endif; ?>
  </<?= $href ? 'a' : 'div' ?>>
  <?php endforeach; ?>
  <p class="k" style="margin-top:12px">You can turn notifications off in Profile &amp; Settings.</p>
</div>
<?php ef_page_end(['neutral' => true, 'active' => 'notif']);
