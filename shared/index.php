<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$groups = shared_groups_of($conn, $user_id);
// Totals across all groups, per currency, plus the payments the user still has to make or receive.
$nets = shared_group_nets($conn, array_map(fn($g) => (int) $g['id'], $groups));
$owed = []; $owe = []; $todo = [];
foreach ($nets as $gidx => $byCur) {
    foreach ($byCur as $cur => $byUser) {
        $n = $byUser[$user_id] ?? 0;
        if ($n > 0) { $owed[$cur] = ($owed[$cur] ?? 0) + $n; } elseif ($n < 0) { $owe[$cur] = ($owe[$cur] ?? 0) - $n; }
        foreach (shared_simplify($byUser) as $p) {
            if ($p['from'] === $user_id || $p['to'] === $user_id) { $p['group'] = $gidx; $p['currency'] = $cur; $todo[] = $p; }
        }
    }
}
$gnames = array_column($groups, 'name', 'id');
$other = shared_names($conn, array_map(fn($p) => $p['from'] === $user_id ? $p['to'] : $p['from'], $todo));
$fmt = function (array $byCur, string $sign = '') {
    if (!$byCur) { return '<span>0.00</span>'; }
    arsort($byCur);
    $h = '';
    foreach (array_slice($byCur, 0, 3, true) as $cur => $v) { $h .= '<div>' . $sign . shared_money($v, $cur) . '</div>'; }
    return $h;
};
$netAll = $owed;
foreach ($owe as $cur => $v) { $netAll[$cur] = ($netAll[$cur] ?? 0) - $v; }
$netAll = array_filter($netAll);

ef_page_start(['title' => 'Shared Overview', 'sub' => 'Who owes whom, across all groups', 'mode' => 's', 'active' => 'shome', 'user' => $user]);
?>
<div class="G3">
  <div class="g card hero"><div class="k">Net balance</div><div class="v"><?php if ($netAll): foreach (array_slice($netAll, 0, 3, true) as $cur => $v): ?><div><?= $v < 0 ? '−' : '' ?><?= shared_money(abs($v), $cur) ?></div><?php endforeach; else: ?>0.00<?php endif; ?></div><span class="badge"><?= $netAll ? 'Across all groups' : 'All settled' ?></span></div>
  <div class="g card"><div class="k">You are owed</div><div class="v in"><?= $fmt($owed) ?></div></div>
  <div class="g card"><div class="k">You owe</div><div class="v ex"><?= $fmt($owe) ?></div></div>
</div>

<?php if ($todo): ?>
<div class="g card" style="margin-bottom:14px">
  <div class="t">To settle</div>
  <?php foreach ($todo as $p): $pay = $p['from'] === $user_id; $oid = $pay ? $p['to'] : $p['from']; ?>
  <div class="row"><span><?= $pay ? 'You pay ' : '' ?><?= e($other[$oid] ?? 'Former member') ?><?= $pay ? '' : ' pays you' ?><small><?= e($gnames[$p['group']] ?? 'Group') ?></small></span>
    <b class="<?= $pay ? 'ex' : 'in' ?>"><?= shared_money($p['amount'], $p['currency']) ?></b>
    <a class="btn sm o" href="<?= e(ef_url('shared/settle.php?' . http_build_query(['group' => $p['group'], 'from' => $p['from'], 'to' => $p['to'], 'amount' => shared_from_minor($p['amount']), 'currency' => $p['currency']]))) ?>">Settle</a></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

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
