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
<?php $mu = friends_username_of($conn, $user_id); shared_panel('Add a friend', 'pl', 'By email or @username'); ?>
  <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="frform">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <input class="inp" type="text" name="email" required maxlength="150" placeholder="<?= e(t('friend@example.com or @username')) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" aria-label="<?= e(t('Friend\'s email or username')) ?>">
    <button class="btn" type="submit"><?= ef_icon('pl') ?> <?= e(t('Send request')) ?></button>
  </form>
  <p class="k fr-hint"><?= e(t('They become your friend once they accept. You can also invite people with a group link.')) ?></p>
  <div class="fr-me"><?= ef_icon('usr') ?><span><?= $mu ? e(t('Your username:')) . ' <b>@' . e($mu) . '</b>' : e(t('You have no username yet')) ?></span><a href="<?= e(ef_url('settings/account.php')) ?>"><?= e($mu ? t('Change') : t('Set one')) ?></a></div>
<?php shared_panel_end(); ?>
<?php $pend = friends_pending($conn, $user_id); ?>
<?php if ($pend['in'] || $pend['out']): ?>
<?php shared_panel('Requests', 'usrp', count($pend['in']) + count($pend['out']) . ' waiting', (string) (count($pend['in']) + count($pend['out'])), 'orange'); ?>
  <?php foreach ($pend['in'] as $r): ?>
  <div class="row"><span><?= e($r['name']) ?><small><?= e(t('wants to be your friend')) ?></small></span>
    <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn sm" name="do" value="accept"><?= e(t('Accept')) ?></button> <button class="btn sm o" name="do" value="decline"><?= e(t('Decline')) ?></button></form></div>
  <?php endforeach; foreach ($pend['out'] as $r): ?>
  <div class="row"><span><?= e($r['name']) ?><small><?= e(t('request sent, waiting')) ?></small></span>
    <form method="post" action="<?= e(ef_url('friends/action.php')) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <button class="btn sm o" name="do" value="cancel"><?= e(t('Cancel')) ?></button></form></div>
  <?php endforeach; ?>
<?php shared_panel_end(); ?>
<?php endif; ?>

<?php shared_panel('Your friends', 'usr', $friends ? (count($friends) === 1 ? t('1 friend') : t('%d friends', count($friends))) : 'No friends yet', $friends ? (string) count($friends) : ''); ?>
  <?php if (count($friends) > 6 || $q !== ''): ?><form method="get" class="sbar" role="search" style="margin-bottom:8px">
    <?= ef_icon('search') ?>
    <input type="search" name="q" id="frq" value="<?= e($q) ?>" placeholder="<?= e(t('Search by name')) ?>" maxlength="60" autocomplete="off" aria-label="<?= e(t('Search friends')) ?>">
    <?php if ($q !== ''): ?><a class="sx" href="<?= e(ef_url('friends/index.php')) ?>" aria-label="<?= e(t('Clear search')) ?>">&times;</a><?php endif; ?>
  </form><?php endif; ?>
  <?php if ($friends): $i = 0; foreach ($friends as $f): $net = friends_net($f); $i++; ?>
  <a class="row frrow" data-n="<?= e(mb_strtolower($f['name'])) ?>" href="<?= e(ef_url('friends/view.php?id=' . (int) $f['id'])) ?>" style="text-decoration:none;color:inherit">
    <?= shared_avatar_for((int) $f['id'], $f['name']) ?>
    <span><?= e($f['name']) ?><small><?= e($f['groups'] ? (count($f['groups']) === 1 ? t('1 shared group') : t('%d shared groups', count($f['groups']))) : t('Added directly')) ?></small></span>
    <?php if ($net): ?>
      <b class="<?= max($net) > 0 ? 'in' : 'ex' ?>"><?= e($phrase($net)) ?></b>
    <?php else: ?>
      <small><?= e(t('settled up')) ?></small>
    <?php endif; ?>
    <?= ef_icon('chev') ?>
  </a>
  <?php endforeach; else: ?>
    <p class="k" style="padding:12px 0"><?= e($q !== '' ? t('No friend matches that name.') : t('No friends yet. Add one above by email or @username, or create a group and share its invite link.')) ?></p>
    <?php if ($q === ''): ?><a class="btn" href="<?= e(ef_url('shared/groups.php')) ?>"><?= ef_icon('pl') ?> <?= e(t('Create a group')) ?></a><?php endif; ?>
  <?php endif; ?>
<?php shared_panel_end(); ?>
<script>(function(){var i=document.getElementById('frq');if(!i)return;i.addEventListener('input',function(){var v=i.value.trim().toLowerCase();document.querySelectorAll('.frrow').forEach(function(r){r.style.display=!v||r.dataset.n.indexOf(v)>-1?'':'none'})});i.form.addEventListener('submit',function(e){e.preventDefault()})})();</script>
<?php ef_page_end(['mode' => 's', 'active' => 'fr']);
