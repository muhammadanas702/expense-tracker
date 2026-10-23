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
<?php $pend = friends_pending($conn, $user_id); ?>
<div class="G2 fr-top">
  <div class="g card">
    <div class="t">Add a friend</div>
    <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="frform">
      <?= csrf_field() ?><input type="hidden" name="do" value="add">
      <input type="email" name="email" required maxlength="150" placeholder="friend@example.com" autocomplete="off" aria-label="Friend's email">
      <button class="btn" type="submit"><?= ef_icon('pl') ?> Send request</button>
    </form>
    <p class="k">Enter the email they used for ExpenseFlow. They get a request and become your friend once they accept. You can also invite people through a group link.</p>
  </div>
  <div class="g card">
    <div class="t">Find in your friends</div>
    <form method="get" class="sbar" role="search">
      <?= ef_icon('search') ?>
      <input type="search" name="q" id="frq" value="<?= e($q) ?>" placeholder="Search by name" maxlength="60" autocomplete="off" aria-label="Search friends">
      <?php if ($q !== ''): ?><a class="sx" href="<?= e(ef_url('friends/index.php')) ?>" aria-label="Clear search">&times;</a><?php endif; ?>
    </form>
    <p class="k">Type to filter instantly.</p>
  </div>
</div>

<?php if ($pend['in'] || $pend['out']): ?>
<div class="g card" style="margin-bottom:14px">
  <div class="t">Requests</div>
  <?php foreach ($pend['in'] as $r): ?>
  <div class="row"><span><?= e($r['name']) ?><small>wants to be your friend</small></span>
    <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn sm" name="do" value="accept">Accept</button> <button class="btn sm o" name="do" value="decline">Decline</button></form></div>
  <?php endforeach; foreach ($pend['out'] as $r): ?>
  <div class="row"><span><?= e($r['name']) ?><small>request sent, waiting</small></span>
    <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn sm o" name="do" value="cancel">Cancel</button></form></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="g card">
  <div class="t">Your friends (<?= count($friends) ?>)</div>
  <?php if ($friends): $i = 0; foreach ($friends as $f): $net = friends_net($f); $i++; ?>
  <a class="row frrow" data-n="<?= e(mb_strtolower($f['name'])) ?>" href="<?= e(ef_url('friends/view.php?id=' . (int) $f['id'])) ?>" style="text-decoration:none;color:inherit">
    <?= shared_avatar($f['name'], $i) ?>
    <span><?= e($f['name']) ?><small><?= $f['groups'] ? count($f['groups']) . ' shared group' . (count($f['groups']) === 1 ? '' : 's') : 'Added directly' ?></small></span>
    <?php if ($net): ?>
      <b class="<?= max($net) > 0 ? 'in' : 'ex' ?>"><?= e($phrase($net)) ?></b>
    <?php else: ?>
      <small>settled up</small>
    <?php endif; ?>
  </a>
  <?php endforeach; else: ?>
    <p class="k" style="padding:12px 0"><?= $q !== '' ? 'No friend matches that name.' : 'No friends yet. Add one by email above, or create a group and share its invite link.' ?></p>
    <?php if ($q === ''): ?><a class="btn" href="<?= e(ef_url('shared/groups.php')) ?>"><?= ef_icon('pl') ?> Create a group</a><?php endif; ?>
  <?php endif; ?>
</div>
<script>(function(){var i=document.getElementById('frq');if(!i)return;i.addEventListener('input',function(){var v=i.value.trim().toLowerCase();document.querySelectorAll('.frrow').forEach(function(r){r.style.display=!v||r.dataset.n.indexOf(v)>-1?'':'none'})});i.form.addEventListener('submit',function(e){e.preventDefault()})})();</script>
<?php ef_page_end(['mode' => 's', 'active' => 'fr']);
