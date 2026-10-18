<?php
require_once __DIR__ . '/../modules/friends/boot.php';
$fid = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$f = $fid ? friends_get($conn, $user_id, $fid) : null;
if (!$f) {
    $_SESSION['error'] = 'Friend not found.';
    shared_back('friends/index.php');
}
$pays = friends_settlements($conn, $user_id, $fid, array_keys($f['groups']));
$net = friends_net($f);

ef_page_start(['title' => $f['name'], 'sub' => 'Your balance with this friend', 'mode' => 's', 'active' => 'fr', 'user' => $user]);
?>
<div class="G3">
  <div class="g card hero"><div class="k">Net with <?= e($f['name']) ?></div><div class="v"><?php if ($net): foreach (array_slice($net, 0, 3, true) as $cur => $v): ?><div><?= $v < 0 ? '−' : '' ?><?= shared_money(abs($v), $cur) ?></div><?php endforeach; else: ?>0.00<?php endif; ?></div><span class="badge"><?= $net ? 'Open balance' : 'All settled' ?></span></div>
  <div class="g card"><div class="k"><?= e($f['name']) ?> owes you</div><div class="v in"><?php if ($f['owed']): foreach ($f['owed'] as $cur => $v): ?><div><?= shared_money($v, $cur) ?></div><?php endforeach; else: ?><span>0.00</span><?php endif; ?></div></div>
  <div class="g card"><div class="k">You owe <?= e($f['name']) ?></div><div class="v ex"><?php if ($f['owe']): foreach ($f['owe'] as $cur => $v): ?><div><?= shared_money($v, $cur) ?></div><?php endforeach; else: ?><span>0.00</span><?php endif; ?></div></div>
</div>

<?php if ($f['todo']): ?>
<div class="g card" style="margin:14px 0">
  <div class="t">To settle</div>
  <?php foreach ($f['todo'] as $p): $pay = $p['from'] === $user_id; ?>
  <div class="row"><span><?= $pay ? 'You pay ' . e($f['name']) : e($f['name']) . ' pays you' ?><small><?= e($f['groups'][$p['group']] ?? 'Group') ?></small></span>
    <b class="<?= $pay ? 'ex' : 'in' ?>"><?= shared_money($p['amount'], $p['currency']) ?></b>
    <a class="btn sm o" href="<?= e(ef_url('shared/settle.php?' . http_build_query(['group' => $p['group'], 'from' => $p['from'], 'to' => $p['to'], 'amount' => shared_from_minor($p['amount']), 'currency' => $p['currency']]))) ?>">Settle</a></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="g card" style="margin:14px 0">
  <div class="t">Shared groups</div>
  <?php foreach ($f['groups'] as $gid => $gname): ?>
  <a class="row" href="<?= e(ef_url('shared/group.php?id=' . (int) $gid)) ?>" style="text-decoration:none;color:inherit"><span><?= e($gname) ?></span><?= ef_icon('chev') ?></a>
  <?php endforeach; ?>
</div>

<div class="g card">
  <div class="t">Payments between you</div>
  <?php if ($pays): foreach ($pays as $s): $mine = (int) $s['from_user'] === $user_id; ?>
  <div class="row"><span><?= $mine ? 'You paid ' . e($f['name']) : e($f['name']) . ' paid you' ?><small><?= e($s['group_name']) ?> · <?= e($s['settled_on']) ?></small></span>
    <b class="<?= $mine ? 'ex' : 'in' ?>"><?= shared_money((int) $s['amount_minor'], $s['currency']) ?></b></div>
  <?php endforeach; else: ?>
  <p class="k" style="padding:12px 0">No payments recorded yet.</p>
  <?php endif; ?>
</div>
<p style="margin-top:14px"><a class="btn o sm" href="<?= e(ef_url('friends/index.php')) ?>"><?= ef_icon('bk') ?> All friends</a></p>
<?php ef_page_end(['mode' => 's', 'active' => 'fr']);
