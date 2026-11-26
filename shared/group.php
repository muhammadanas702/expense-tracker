<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$gid = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$isOwner = $group['my_role'] === 'owner';
$isDirect = !empty($group['is_direct']);
$members = shared_members_of($conn, $gid);
$csrf = csrf_token();
$invite = shared_invite_url($group['invite_code']);
$wa = 'https://wa.me/?text=' . rawurlencode('Join my ExpenseFlow group "' . $group['name'] . '": ' . $invite);
$action = ef_url('shared/action.php');
$expenses = shared_expenses_of($conn, $gid, $user_id);
$totals = shared_group_totals($conn, $gid);
$parts = shared_group_parts($conn, [$gid])[$gid] ?? [];
$nets = [];
foreach ($parts as $cur => $byUser) {
    foreach ($byUser as $uid => $pt) { $nets[$cur][$uid] = $pt['paid'] - $pt['share'] + $pt['sent'] - $pt['recv']; }
}
$settlements = shared_settlements_of($conn, $gid);
$pairs = shared_group_pairs($conn, [$gid])[$gid] ?? [];
$nameIds = array_merge([], ...array_values(array_map('array_keys', $nets)));
foreach ($pairs as $list) { foreach ($list as $p) { $nameIds[] = $p['from']; $nameIds[] = $p['to']; } }
$nameMap = shared_names($conn, $nameIds);
$nm = fn(int $id) => $nameMap[$id] ?? t('Former member');
$cmts = (ef_feature_on('comments') && function_exists('comments_for_expenses')) ? comments_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$rcpts = (ef_feature_on('receipts') && function_exists('receipts_for_expenses')) ? receipts_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$splitNames = [];
if ($expenses) {
    $ids = array_map(fn($x) => (int) $x['id'], $expenses);
    $q = $conn->query('SELECT s.expense_id, s.share_minor, u.name FROM shared_splits s LEFT JOIN users u ON u.id = s.user_id WHERE s.expense_id IN (' . implode(',', $ids) . ') ORDER BY s.user_id');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $splitNames[(int) $r['expense_id']][] = [$r['name'] ?? t('Former member'), (int) $r['share_minor']];
    }
}

$debts = [];
foreach ($pairs as $cur => $list) {
    foreach ($list as $p) { $p['currency'] = $cur; $debts[] = $p; }
}
$mine = array_values(array_filter($debts, fn($p) => $p['from'] === $user_id || $p['to'] === $user_id));
$others = array_values(array_filter($debts, fn($p) => $p['from'] !== $user_id && $p['to'] !== $user_id));
$iOwe = []; $iGet = [];
foreach ($mine as $p) {
    if ($p['from'] === $user_id) { $iOwe[$p['currency']] = ($iOwe[$p['currency']] ?? 0) + $p['amount']; }
    else { $iGet[$p['currency']] = ($iGet[$p['currency']] ?? 0) + $p['amount']; }
}
$settleUrl = fn(array $p) => ef_url('shared/settle.php?' . http_build_query(['group' => $gid, 'from' => $p['from'], 'to' => $p['to'], 'amount' => shared_from_minor($p['amount']), 'currency' => $p['currency']]));
$debtRow = function (array $p) use ($user_id, $isOwner, $nm, $settleUrl) {
    $mineFrom = $p['from'] === $user_id; $mineTo = $p['to'] === $user_id;
    $who = $mineFrom ? (int) $p['to'] : (int) $p['from'];
    $h = '<div class="row">' . ($mineFrom || $mineTo ? shared_avatar_for($who, $nm($who)) : '') . '<span>' . ($mineFrom ? '<b>You</b> pay ' : e($nm($p['from'])) . ' pays ') . ($mineTo ? '<b>you</b>' : e($nm($p['to']))) . '</span>';
    $h .= '<b class="' . ($mineFrom ? 'ex' : ($mineTo ? 'in' : '')) . '">' . shared_money($p['amount'], $p['currency']) . '</b>';
    if ($mineFrom || $mineTo || $isOwner) { $h .= '<a class="btn sm o" href="' . e($settleUrl($p)) . '">Settle</a>'; }
    return $h . '</div>';
};

ef_page_start(['title' => $group['name'], 'sub' => count($members) . ' member' . (count($members) === 1 ? '' : 's'), 'mode' => 's', 'active' => 'grp', 'user' => $user]);
?>
<div class="g card hero">
  <div class="gh">
    <div class="gi" style="margin:0"><?= ef_icon(shared_icon_key($group['icon']), '#1d4ed8') ?></div>
    <div style="flex:1;min-width:0"><div class="k"><?= e(t('Members')) ?></div>
      <div class="stk" style="margin-top:6px"><?php foreach (array_slice($members, 0, 6) as $i => $m) { echo shared_avatar_for((int) $m['id'], $m['name']); } ?></div></div>
    <div style="text-align:right"><div class="k"><?= e(t('Total spent')) ?></div>
      <?php if ($totals): foreach (array_slice($totals, 0, 3) as $t): ?><div class="v" style="margin:0;font-size:1.15rem"><?= shared_money((int) $t['total'], $t['currency']) ?></div><?php endforeach; else: ?><div class="v" style="margin:0">0.00</div><?php endif; ?></div>
  </div>
</div>

<div class="g card sg-me">
  <div class="sg-state">
    <div class="k"><?= e(t('Your balance in this group')) ?></div>
    <?php if ($iOwe || $iGet): ?>
      <?php foreach ($iGet as $cur => $v): ?><div class="sg-big in"><?= e(t('You get')) ?> <?= shared_money($v, $cur) ?></div><?php endforeach; ?>
      <?php foreach ($iOwe as $cur => $v): ?><div class="sg-big ex"><?= e(t('You owe')) ?> <?= shared_money($v, $cur) ?></div><?php endforeach; ?>
    <?php else: ?>
      <div class="sg-big ok"><?= $expenses || $settlements ? 'All settled up' : 'No expenses yet' ?></div>
    <?php endif; ?>
  </div>
  <div class="sg-btns">
    <a class="btn" href="<?= e(ef_url('shared/add.php?group=' . $gid)) ?>"><?= ef_icon('pl') ?> <?= e(t('Add expense')) ?></a>
    <a class="btn o" href="<?= e(ef_url('shared/settle.php?group=' . $gid)) ?>"><?= e(t('Record a payment')) ?></a>
  </div>
</div>

<?php
  $nPay = count(array_filter($mine, fn($p) => $p['from'] === $user_id)); $nGet = count(array_filter($mine, fn($p) => $p['to'] === $user_id));
  $meta = !$debts ? t('All settled up') : (!$mine ? t('Nothing for you to pay or receive') : (($iOwe ? t('You pay %d', $nPay) . ' · ' : '') . ($iGet ? t('You get from %d', $nGet) : '')));
  shared_panel('Settle up', 'send', rtrim($meta, ' ·'), $mine ? (string) count($mine) : '', 'orange');
?>
  <?php if (!$debts): ?><p class="k" style="padding:12px 0 4px"><?= e(t('Everyone is settled up. What you owe each other adds up to zero, so nothing is left to pay.')) ?></p>
  <?php elseif (!$mine): ?><p class="k" style="padding:12px 0 4px"><?= e(t('You have nothing to pay or receive here.')) ?></p><?php endif; ?>
  <?php foreach ($mine as $p) { echo $debtRow($p); } ?>
  <?php if ($others): ?>
  <details class="sg-d"><summary><?= e(t('Between other members (%d)', count($others))) ?></summary>
    <div class="sg-body"><?php foreach ($others as $p) { echo $debtRow($p); } ?></div>
  </details>
  <?php endif; ?>
<?php shared_panel_end(); ?>

<?php
$firstTot = $totals ? shared_money((int) $totals[0]['total'], $totals[0]['currency']) : '';
shared_panel('Expenses', 'wal', $expenses ? (count($expenses) === 1 ? t('1 expense') : t('%d expenses', count($expenses))) : t('No expenses yet'), (string) count($expenses));
?>
  <?php if (!$expenses): ?><p class="k" style="padding:12px 0 4px"><?= e(t('No expenses yet. Tap "Add expense" and choose how to split it.')) ?></p><?php endif; ?>
  <?php foreach ($expenses as $x):
    $cur = $x['currency']; $mineShare = $x['my_share'] !== null ? (int) $x['my_share'] : null;
    $paidMe = (int) $x['paid_by'] === $user_id; $total = (int) $x['amount_minor'];
    if ($paidMe) { $net = $total - (int) ($mineShare ?? 0); $label = $net > 0 ? t('you lent') : t('you paid'); $cls = 'tg'; }
    elseif ($mineShare) { $net = $mineShare; $label = t('you owe'); $cls = 'tx'; }
    else { $net = 0; $label = t('not involved'); $cls = 'tn'; }
    $can = shared_can_edit($x, $group, $user_id, $conn);
    $xc = (ef_feature_on('comments') && function_exists('comments_add')) ? ($cmts[(int) $x['id']] ?? []) : null;
    $open = $xc && isset($_GET['c']) && (int) $_GET['c'] === (int) $x['id'];
  ?>
  <div class="row sg-x" id="x<?= (int) $x['id'] ?>">
    <div class="sg-xh"><span class="sg-xm"><b><?= e($x['title']) ?></b><?php if (!empty($rcpts[(int) $x['id']])): ?> <a href="<?= e(ef_url('receipts/view.php?id=' . (int) $rcpts[(int) $x['id']])) ?>" target="_blank" rel="noopener" title="<?= e(t('View receipt')) ?>" style="color:var(--at,var(--a1))"><?= ef_icon('rcp') ?></a><?php endif; ?>
      <small><?= e($x['payer_name'] ?? t('Former member')) ?> <?= e(t('paid')) ?> <?= shared_money($total, $cur) ?> · <?= e(date('d M Y', strtotime($x['expense_date']))) ?></small></span>
    <span class="tag <?= $cls ?>"><?= e($label) ?><?= $net > 0 ? ' ' . shared_money($net, $cur) : '' ?></span></div>
      <details class="sg-d"<?= $open ? ' open' : '' ?>><summary><?= e(t('Details')) ?><?= $xc ? ' · ' . (count($xc) === 1 ? t('1 comment') : t('%d comments', count($xc))) : '' ?></summary>
        <div class="sg-body">
          <div><div class="k"><?= e(t('Split:')) ?> <?= e(t(SHARED_SPLIT_TYPES[$x['split_type']] ?? 'Split')) ?></div>
            <?php foreach ($splitNames[(int) $x['id']] ?? [] as [$sn, $sh]): ?><div class="sg-ln"><span><?= e($sn) ?></span><b><?= shared_money($sh, $cur) ?></b></div><?php endforeach; ?></div>
          <?php if (!empty($x['note'])): ?><div class="k"><?= e(t('Note:')) ?> <?= e($x['note']) ?></div><?php endif; ?>
          <?php if ($xc !== null): ?>
          <div>
            <div class="k"><?= ef_icon('msg') ?> <?= e(t('Comments')) ?><?= $xc ? ' (' . count($xc) . ')' : '' ?></div>
            <?php foreach ($xc as $c): ?>
            <small style="display:flex;gap:8px;align-items:flex-start;margin:6px 0"><span style="flex:1;overflow-wrap:anywhere"><b><?= (int) $c['user_id'] === $user_id ? e(t('You')) : e($c['name'] ?? t('Former member')) ?></b> · <?= function_exists('activity_ago') ? e(activity_ago((int) $c['age_s'], $c['created_at'])) : '' ?><br><?= nl2br(e($c['body'])) ?></span>
              <?php if ((int) $c['user_id'] === $user_id || $isOwner): ?><a class="del" href="<?= e(ef_url('comments/action.php')) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>,"comment_id":<?= (int) $c['id'] ?>}' onclick="return confirm(<?= e(json_encode(t('Delete this comment?'), JSON_UNESCAPED_UNICODE)) ?>)" aria-label="<?= e(t('Delete comment')) ?>"><?= ef_icon('tr') ?></a><?php endif; ?></small>
            <?php endforeach; ?>
            <form method="POST" action="<?= e(ef_url('comments/action.php')) ?>" autocomplete="off" style="display:flex;gap:8px;margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="expense_id" value="<?= (int) $x['id'] ?>">
              <input class="inp sg-cin" type="text" name="body" maxlength="500" placeholder="<?= e(t('Write a comment…')) ?>" aria-label="<?= e(t('Comment')) ?>" required>
              <button class="btn sm" type="submit"><?= e(t('Send')) ?></button>
            </form>
          </div>
          <?php endif; ?>
          <?php if ($can): ?>
          <div class="sg-acts">
            <a class="btn sm o" href="<?= e(ef_url('shared/add.php?edit=' . (int) $x['id'])) ?>"><?= ef_icon('pen') ?> <?= e(t('Edit')) ?></a>
            <a class="btn sm o sg-del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_expense","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>}' onclick="return confirm(<?= e(json_encode(t('Delete this expense for everyone in the group?'), JSON_UNESCAPED_UNICODE)) ?>)"><?= ef_icon('tr') ?> <?= e(t('Delete')) ?></a>
          </div>
          <?php endif; ?>
        </div>
      </details>
  </div>
  <?php endforeach; ?>
<?php shared_panel_end(); ?>

<?php $lastSt = $settlements[0] ?? null; shared_sec('grp-payments', 'Payments made', 'ok', 'blue', false, !$lastSt ? t('No payments yet') : t('Last:') . ' ' . ($lastSt['from_name'] ?? t('Former member')) . ' ' . t('paid') . ' ' . ($lastSt['to_name'] ?? t('Former member')) . ' ' . shared_money((int) $lastSt['amount_minor'], $lastSt['currency']), (string) count($settlements)); ?>
  <?php if (!$settlements): ?><p class="k"><?= e(t('No payments recorded yet.')) ?></p><?php endif; ?>
  <?php foreach ($settlements as $st): $canDel = shared_can_manage((int) $st['created_by'], $gid, $group, $user_id, $conn); ?>
  <div class="row">
    <span><?= e($st['from_name'] ?? t('Former member')) ?> <?= e(t('paid')) ?> <?= e($st['to_name'] ?? t('Former member')) ?><small><?= e(date('d M Y', strtotime($st['settled_on']))) ?><?= $st['note'] ? ' · ' . e($st['note']) : '' ?></small></span>
    <b><?= shared_money((int) $st['amount_minor'], $st['currency']) ?></b>
    <?php if ($canDel): ?><span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_settlement","group_id":<?= $gid ?>,"settlement_id":<?= (int) $st['id'] ?>}' onclick="return confirm(<?= e(json_encode(t('Delete this payment record? Balances will change.'), JSON_UNESCAPED_UNICODE)) ?>)" aria-label="<?= e(t('Delete payment')) ?>"><?= ef_icon('tr') ?></a></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php shared_sec_end(); ?>

<?php shared_sec('grp-balance', "Everyone's balance", 'bar', 'blue', false, 'Who paid how much and who gets back'); ?>
    <?php if (!$nets): ?><p class="k"><?= e(t('Nothing to show yet. Balances appear after the first expense.')) ?></p><?php endif; ?>
    <?php foreach ($nets as $cur => $byUser): ksort($byUser); ?>
      <div class="k" style="margin-top:6px"><?= e($cur) ?></div>
      <?php foreach ($byUser as $uid => $n): $pt = $parts[$cur][$uid]; ?>
      <div class="row"><span><?= $uid === $user_id ? e(t('You')) : e($nm($uid)) ?><small><?= e(t('paid')) ?> <?= shared_money($pt['paid'], $cur) ?> <?= e(t('· share')) ?> <?= shared_money($pt['share'], $cur) ?><?= ($pt['sent'] || $pt['recv']) ? ' · ' . t('paid back %s', shared_money($pt['sent'], $cur)) . ' · ' . t('got back %s', shared_money($pt['recv'], $cur)) : '' ?></small></span>
        <b class="<?= $n > 0 ? 'in' : ($n < 0 ? 'ex' : '') ?>"><?= $n > 0 ? 'gets ' : ($n < 0 ? 'owes ' : 'settled ') ?><?= $n === 0 ? '' : shared_money(abs($n), $cur) ?></b></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
<?php shared_sec_end(); ?>

<?php if (!$isDirect): ?>
<?php shared_sec('grp-members', 'Members', 'usr', 'blue', false, implode(', ', array_map(fn($m) => $m['name'], array_slice($members, 0, 4))) . (count($members) > 4 ? ' +' . (count($members) - 4) : ''), (string) count($members)); ?>
    <?php foreach ($members as $i => $m): ?>
    <div class="row">
      <?= shared_avatar_for((int) $m['id'], $m['name']) ?>
      <span><?= e($m['name']) ?><?= (int) $m['id'] === $user_id ? ' ' . t('(you)') : '' ?><small><?= $m['role'] === 'owner' ? e(t('Owner')) : e(t('Member')) ?> <?= e(t('· joined')) ?> <?= e(date('d M Y', strtotime($m['joined_at']))) ?></small></span>
      <?php if ($isOwner && (int) $m['id'] !== $user_id): ?>
      <span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"remove","group_id":<?= $gid ?>,"user_id":<?= (int) $m['id'] ?>}' onclick="return confirm('Remove <?= e(addslashes($m['name'])) ?> from this group?')" aria-label="<?= e(t('Remove %s', $m['name'])) ?>"><?= ef_icon('tr') ?></a></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php shared_sec_end(); ?>
<?php $invitable = function_exists('shared_invitable_friends') && function_exists('friends_direct_ids') ? shared_invitable_friends($conn, $gid, $user_id) : []; ?>
<?php shared_sec('grp-invite', 'Add people', 'link', 'blue', false, $invitable ? 'Pick a friend, or share the link' : 'Share the link on WhatsApp or copy it'); ?>
    <?php if ($invitable): ?>
    <div class="k" style="margin:2px 0 4px"><b><?= e(t('Your friends')) ?></b> <?= e(t('· they join after they accept')) ?></div>
    <?php foreach ($invitable as $f): ?>
    <div class="row"><?= shared_avatar_for((int) $f['id'], $f['name']) ?><span><?= e($f['name']) ?></span>
      <?php if ((int) $f['invited']): ?><small><?= e(t('Invited')) ?></small>
      <?php else: ?><form method="post" action="<?= e($action) ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="invite_friend"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="friend_id" value="<?= (int) $f['id'] ?>"><button class="btn sm" type="submit"><?= e(t('Add')) ?></button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <div class="k" style="margin:14px 0 6px"><b><?= e(t('Not a friend yet?')) ?></b> <?= e(t('Send them this link')) ?></div>
    <?php endif; ?>
    <div class="fl"><label for="invite"><?= e(t('Invite link')) ?></label><input class="inp" id="invite" type="text" value="<?= e($invite) ?>" readonly onclick="this.select()"></div>
    <div class="sg-acts">
      <button class="btn" type="button" id="copy-invite"><?= ef_icon('link') ?> <?= e(t('Copy link')) ?></button>
      <a class="btn i2" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= ef_icon('msg') ?> <?= e(t('WhatsApp')) ?></a>
    </div>
    <p class="k" style="margin-top:10px"><?= e(t('Anyone with this link can join after signing in.')) ?><?= $isOwner ? ' ' . e(t('Made a mistake or shared it with the wrong person? Create a new link and the old one stops working.')) : '' ?></p>
    <?php if ($isOwner): ?>
    <a class="btn sm o" style="margin-top:8px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"regen","group_id":<?= $gid ?>}' onclick="return confirm(<?= e(json_encode(t('Create a new link? The old link will stop working.'), JSON_UNESCAPED_UNICODE)) ?>)"><?= e(t('Create new link')) ?></a>
    <?php endif; ?>
<?php shared_sec_end(); ?>

<?php shared_sec('grp-settings', $isOwner ? t('Group settings') : t('Leave group'), $isOwner ? 'set' : 'out', 'blue', false, $isOwner ? t('Rename, change icon, delete the group') : t('Stop seeing this group')); ?>
    <?php if ($isOwner): ?>
    <form method="POST" action="<?= e($action) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
      <div class="fl"><label for="gname"><?= e(t('Group name')) ?></label><input class="inp" id="gname" type="text" name="name" maxlength="60" value="<?= e($group['name']) ?>" required></div>
      <div class="fl"><label><?= e(t('Icon')) ?></label>
        <div class="chips">
          <?php foreach (shared_icons() as $key => $label): ?>
          <label><input type="radio" name="icon" value="<?= e($key) ?>"<?= $group['icon'] === $key ? ' checked' : '' ?>><span class="chip"><?= ef_icon($key) ?><?= e(t($label)) ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <button class="btn" type="submit"><?= e(t('Save changes')) ?></button>
    </form>
    <div class="sg-danger">
      <b><?= ef_icon('warn', '#ea580c') ?> <?= e(t('Delete group')) ?></b>
      <p class="k"><?= e(t('Deletes the group, its expenses and payments for everyone. This cannot be undone.')) ?></p>
      <a class="btn sm o sg-del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>}' onclick="return confirm(<?= e(json_encode(t('Delete this group for everyone?'), JSON_UNESCAPED_UNICODE)) ?>)"><?= e(t('Delete group')) ?></a>
    </div>
    <?php else: ?>
    <p class="k"><?= e(t('You can leave once you owe nothing and are owed nothing. Your past expenses stay in the group for the others.')) ?></p>
    <a class="btn sm o sg-del" style="margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"leave","group_id":<?= $gid ?>}' onclick="return confirm(<?= e(json_encode(t('Leave this group?'), JSON_UNESCAPED_UNICODE)) ?>)"><?= e(t('Leave group')) ?></a>
    <?php endif; ?>
<?php shared_sec_end(); ?>
<?php endif; ?>
<?php
$js = shared_sec_js() . '<script>(function(){var b=document.getElementById("copy-invite"),i=document.getElementById("invite");if(!b)return;b.addEventListener("click",function(){i.select();var done=function(){b.lastChild.textContent=" Copied!";setTimeout(function(){b.lastChild.textContent=" Copy link"},1800)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done()})}else{document.execCommand("copy");done()}})})()</script>';
ef_page_end(['mode' => 's', 'active' => 'grp', 'scripts' => $js]);
