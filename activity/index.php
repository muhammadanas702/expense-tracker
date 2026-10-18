<?php
require_once __DIR__ . '/../modules/activity/boot.php';
$groups = shared_groups_of($conn, $user_id);
$gid = filter_var($_GET['group'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($gid && !array_filter($groups, fn($g) => (int) $g['id'] === $gid)) {
    $gid = 0; // not one of my groups
}
$rows = activity_feed($conn, $user_id, $gid);
$buckets = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];
foreach ($rows as $r) {
    $buckets[(int) $r['day_diff'] === 0 ? 'Today' : ((int) $r['day_diff'] === 1 ? 'Yesterday' : 'Earlier')][] = $r;
}
$unread = activity_unread_count($conn, $user_id);

ef_page_start(['title' => 'Activity', 'sub' => 'Everything happening in your groups', 'mode' => 's', 'active' => 'act', 'user' => $user]);
?>
<div class="g card" style="margin-bottom:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
  <div class="chips" style="flex:1;min-width:0">
    <a class="chip<?= $gid === 0 ? ' on' : '' ?>" href="<?= e(ef_url('activity/index.php')) ?>">All groups</a>
    <?php foreach ($groups as $g): ?>
    <a class="chip<?= (int) $g['id'] === $gid ? ' on' : '' ?>" href="<?= e(ef_url('activity/index.php?group=' . (int) $g['id'])) ?>"><?= ef_icon(shared_icon_key($g['icon'])) ?><?= e($g['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <a class="btn o sm" href="<?= e(ef_url('activity/notifications.php')) ?>"><?= ef_icon('bell') ?> Notifications<?= $unread ? ' (' . $unread . ')' : '' ?></a>
</div>
<?php if (!$rows): ?>
<div class="g card"><p class="k" style="padding:12px 0">Nothing yet. Add an expense or settle up in a group and it shows here.</p></div>
<?php endif; ?>
<?php foreach ($buckets as $label => $list): if (!$list) { continue; } ?>
<div class="g card" style="margin-bottom:14px">
  <div class="t"><?= e($label) ?></div>
  <?php foreach ($list as $r): [$icon, $text, $amt] = activity_describe($r, $user_id); ?>
  <div class="row">
    <span class="gi" style="margin:0;width:36px;height:36px;flex:none"><?= ef_icon($icon, '#1d4ed8') ?></span>
    <span><?= e($text) ?><small><a href="<?= e(ef_url('shared/group.php?id=' . (int) $r['group_id'])) ?>" style="color:inherit"><?= e($r['group_name'] ?? 'Group') ?></a> · <?= e(activity_ago((int) $r['age_s'], $r['created_at'])) ?></small></span>
    <?php if ($amt !== ''): ?><b><?= e($amt) ?></b><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php ef_page_end(['mode' => 's', 'active' => 'act']);
