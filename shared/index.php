<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$groups = shared_groups_of($conn, $user_id);

ef_page_start(['title' => 'Shared Overview', 'sub' => 'Who owes whom, across all groups', 'mode' => 's', 'active' => 'shome', 'user' => $user]);
?>
<div class="G3">
  <div class="g card hero"><div class="k">Net balance</div><div class="v">0.00</div><span class="badge">All settled</span></div>
  <div class="g card"><div class="k">You are owed</div><div class="v in">0.00</div></div>
  <div class="g card"><div class="k">You owe</div><div class="v ex">0.00</div></div>
</div>

<div class="g card">
  <div class="t">Your groups <a href="<?= e(ef_url('shared/groups.php')) ?>"><?= $groups ? 'View all →' : 'Create one →' ?></a></div>
  <?php if ($groups): ?>
  <div class="G4" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
    <?php foreach (array_slice($groups, 0, 6) as $gr): ?>
    <a class="g gc" href="<?= e(ef_url('shared/group.php?id=' . (int) $gr['id'])) ?>">
      <div class="gi"><?= ef_icon(shared_icon_key($gr['icon']), '#1d4ed8') ?></div>
      <b><?= e($gr['name']) ?></b>
      <div class="k" style="margin-top:4px"><?= (int) $gr['member_count'] ?> member<?= (int) $gr['member_count'] === 1 ? '' : 's' ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <p class="k" style="padding:12px 0">No groups yet. Create a group for a trip, rent or any shared bill, then invite your friends with a link.</p>
    <a class="btn" href="<?= e(ef_url('shared/groups.php')) ?>"><?= ef_icon('pl') ?> Create a group</a>
  <?php endif; ?>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'shome']);
