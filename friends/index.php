<?php
require_once __DIR__ . '/../modules/friends/boot.php';
$groups = shared_groups_of($conn, $user_id);
$friends = friends_list($conn, $user_id, $groups);
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $friends = array_filter($friends, fn($f) => stripos($f['name'], $q) !== false);
}
// "owes you PKR 500.00" / "you owe PKR 100.00", one phrase per currency (max 3).
$phrase = function (array $net) {
    $parts = [];
    foreach (array_slice($net, 0, 3, true) as $cur => $v) {
        $parts[] = ($v > 0 ? 'owes you ' : 'you owe ') . shared_money(abs($v), $cur);
    }
    return implode(' · ', $parts);
};

ef_page_start(['title' => 'Friends', 'sub' => 'People you split bills with', 'mode' => 's', 'active' => 'fr', 'user' => $user]);
?>
<div class="g card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search friends" maxlength="60" style="flex:1;min-width:160px">
    <button class="btn o sm" type="submit">Search</button>
  </form>
  <p class="k" style="margin-top:10px">Everyone who is in a group with you appears here automatically. Invite people to a group to add them.</p>
</div>

<div class="g card">
  <div class="t">Your friends (<?= count($friends) ?>)</div>
  <?php if ($friends): $i = 0; foreach ($friends as $f): $net = friends_net($f); $i++; ?>
  <a class="row" href="<?= e(ef_url('friends/view.php?id=' . (int) $f['id'])) ?>" style="text-decoration:none;color:inherit">
    <?= shared_avatar($f['name'], $i) ?>
    <span><?= e($f['name']) ?><small><?= count($f['groups']) ?> shared group<?= count($f['groups']) === 1 ? '' : 's' ?></small></span>
    <?php if ($net): ?>
      <b class="<?= max($net) > 0 ? 'in' : 'ex' ?>"><?= e($phrase($net)) ?></b>
    <?php else: ?>
      <small>settled up</small>
    <?php endif; ?>
  </a>
  <?php endforeach; else: ?>
    <p class="k" style="padding:12px 0"><?= $q !== '' ? 'No friend matches that name.' : 'No friends yet. Create a group and share its invite link.' ?></p>
    <?php if ($q === ''): ?><a class="btn" href="<?= e(ef_url('shared/groups.php')) ?>"><?= ef_icon('pl') ?> Create a group</a><?php endif; ?>
  <?php endif; ?>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'fr']);
