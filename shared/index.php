<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$groups = shared_groups_of($conn, $user_id);
// Totals across all groups, per currency, from the same simplified payments the group pages show.
$sum = shared_user_summary($conn, array_map(fn($g) => (int) $g['id'], $groups), $user_id);
$owed = $sum['owed']; $owe = $sum['owe']; $todo = $sum['todo'];
$gnames = array_column($groups, 'name', 'id');
$other = shared_names($conn, array_map(fn($p) => $p['from'] === $user_id ? $p['to'] : $p['from'], $todo));
$fmt = function (array $byCur, string $sign = '') {
    if (!$byCur) { return '<span class="mu" style="font-size:1rem;font-weight:600">Nothing</span>'; }
    arsort($byCur);
    $h = '';
    foreach (array_slice($byCur, 0, 3, true) as $cur => $v) { $h .= '<div>' . $sign . shared_money($v, $cur) . '</div>'; }
    return $h;
};

ef_page_start(['title' => 'Shared Overview', 'sub' => 'Who owes whom, across all groups', 'mode' => 's', 'active' => 'shome', 'user' => $user]);
?>
<form method="GET" action="<?= e(ef_url('shared/search.php')) ?>" class="g card" id="dash-search" role="search" style="display:flex;gap:8px;align-items:center;padding:10px 12px">
    <?= ef_icon('search') ?>
    <input class="inp" type="search" name="q" maxlength="60" aria-label="Search shared expenses" placeholder="Search shared" style="flex:1;min-width:0;border:0;background:transparent">
    <button class="btn sm" type="submit">Search</button>
</form>
<div class="G2" style="grid-template-columns:repeat(2,minmax(0,1fr));margin-top:14px">
  <div class="g card"><div class="k">You will get</div><div class="v in"><?= $fmt($owed) ?></div></div>
  <div class="g card"><div class="k">You have to pay</div><div class="v ex"><?= $fmt($owe) ?></div></div>
</div>

<?php if ($owed && $owe): ?><p class="k" style="margin:-4px 4px 14px">Each person is counted separately: what one friend owes you does not reduce what you owe someone else.</p><?php endif; ?>
<?php if ($todo): ?>
<?php shared_panel('Settle up', 'send', count($todo) . ' payment' . (count($todo) === 1 ? '' : 's') . ' waiting', (string) count($todo), 'orange'); ?>
  <?php foreach ($todo as $p): $pay = $p['from'] === $user_id; $oid = $pay ? $p['to'] : $p['from']; ?>
  <div class="row"><?= shared_avatar_for((int) $oid, $other[$oid] ?? 'Former member') ?><span><?= $pay ? 'You pay ' : '' ?><?= e($other[$oid] ?? 'Former member') ?><?= $pay ? '' : ' pays you' ?><small><?= e($gnames[$p['group']] ?? 'Group') ?></small></span>
    <b class="<?= $pay ? 'ex' : 'in' ?>"><?= shared_money($p['amount'], $p['currency']) ?></b>
    <a class="btn sm o" href="<?= e(ef_url('shared/settle.php?' . http_build_query(['group' => $p['group'], 'from' => $p['from'], 'to' => $p['to'], 'amount' => shared_from_minor($p['amount']), 'currency' => $p['currency']]))) ?>">Settle</a></div>
  <?php endforeach; ?>
<?php shared_panel_end(); ?>
<?php endif; ?>

<?php $recent = (ef_feature_on('activity') && function_exists('activity_feed')) ? activity_feed($conn, $user_id, 0, 5) : []; ?>
<?php if ($recent): ?>
<?php [$li, $lt, $la] = activity_describe($recent[0], $user_id); shared_panel('Recent activity', 'act'); ?>
  <?php foreach ($recent as $r): [$icon, $text, $amt] = activity_describe($r, $user_id); ?>
  <div class="row">
    <span class="gi" style="margin:0;width:36px;height:36px;flex:none"><?= ef_icon($icon, '#1d4ed8') ?></span>
    <span><?= e($text) ?><small><?= e($r['group_name'] ?? 'Group') ?> · <?= e(activity_ago((int) $r['age_s'], $r['created_at'])) ?></small></span>
    <?php if ($amt !== ''): ?><b><?= e($amt) ?></b><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <a class="btn sm o" style="margin-top:8px" href="<?= e(ef_url('activity/index.php')) ?>">View all activity</a>
<?php shared_panel_end(); ?>
<?php endif; ?>

<?php shared_panel('Your groups', 'users', $groups ? count($groups) . ' group' . (count($groups) === 1 ? '' : 's') : 'No groups yet', $groups ? (string) count($groups) : ''); ?>
  <?php if ($groups): ?>
  <div class="glist">
    <?php foreach (array_slice($groups, 0, 6) as $gr): ?>
    <a class="grow" href="<?= e(ef_url('shared/group.php?id=' . (int) $gr['id'])) ?>">
      <span class="gi"><?= ef_icon(shared_icon_key($gr['icon']), '#1d4ed8') ?></span>
      <span class="gn"><b><?= e($gr['name']) ?></b><small><?= (int) $gr['member_count'] ?> member<?= (int) $gr['member_count'] === 1 ? '' : 's' ?><?= $gr['my_role'] === 'owner' ? ' · owner' : '' ?></small></span>
      <span class="sec-ch" aria-hidden="true"></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <p class="k" style="padding:12px 0">No groups yet. Create a group for a trip, rent or any shared bill, then invite your friends with a link.</p>
    <a class="btn" href="<?= e(ef_url('shared/groups.php')) ?>"><?= ef_icon('pl') ?> Create a group</a>
  <?php endif; ?>
  <?php if ($groups): ?><a class="btn sm o" style="margin-top:10px" href="<?= e(ef_url('shared/groups.php')) ?>">All groups &amp; create new</a><?php endif; ?>
<?php shared_panel_end(); ?>
<?php ef_page_end(['mode' => 's', 'active' => 'shome', 'scripts' => shared_sec_js()]);
