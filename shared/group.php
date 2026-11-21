<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$gid = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$isOwner = $group['my_role'] === 'owner';
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
$nm = fn(int $id) => $nameMap[$id] ?? 'Former member';
$cmts = (ef_feature_on('comments') && function_exists('comments_for_expenses')) ? comments_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$rcpts = (ef_feature_on('receipts') && function_exists('receipts_for_expenses')) ? receipts_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$splitNames = [];
if ($expenses) {
    $ids = array_map(fn($x) => (int) $x['id'], $expenses);
    $q = $conn->query('SELECT s.expense_id, s.share_minor, u.name FROM shared_splits s LEFT JOIN users u ON u.id = s.user_id WHERE s.expense_id IN (' . implode(',', $ids) . ') ORDER BY s.user_id');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $splitNames[(int) $r['expense_id']][] = [$r['name'] ?? 'Former member', (int) $r['share_minor']];
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
    <div style="flex:1;min-width:0"><div class="k">Members</div>
      <div class="stk" style="margin-top:6px"><?php foreach (array_slice($members, 0, 6) as $i => $m) { echo shared_avatar($m['name'], $i); } ?></div></div>
    <div style="text-align:right"><div class="k">Total spent</div>
      <?php if ($totals): foreach (array_slice($totals, 0, 3) as $t): ?><div class="v" style="margin:0;font-size:1.15rem"><?= shared_money((int) $t['total'], $t['currency']) ?></div><?php endforeach; else: ?><div class="v" style="margin:0">0.00</div><?php endif; ?></div>
  </div>
</div>

<div class="g card sg-me">
  <div class="sg-state">
    <div class="k">Your balance in this group</div>
    <?php if ($iOwe || $iGet): ?>
      <?php foreach ($iGet as $cur => $v): ?><div class="sg-big in">You get <?= shared_money($v, $cur) ?></div><?php endforeach; ?>
      <?php foreach ($iOwe as $cur => $v): ?><div class="sg-big ex">You owe <?= shared_money($v, $cur) ?></div><?php endforeach; ?>
    <?php else: ?>
      <div class="sg-big ok"><?= $expenses || $settlements ? 'All settled up' : 'No expenses yet' ?></div>
    <?php endif; ?>
  </div>
  <div class="sg-btns">
    <a class="btn" href="<?= e(ef_url('shared/add.php?group=' . $gid)) ?>"><?= ef_icon('pl') ?> Add expense</a>
    <a class="btn o" href="<?= e(ef_url('shared/settle.php?group=' . $gid)) ?>">Record a payment</a>
  </div>
</div>

<?php if ($debts):
  $meta = !$mine ? 'Nothing for you to pay or receive' : (($iOwe ? 'You pay ' . count(array_filter($mine, fn($p) => $p['from'] === $user_id)) . ' · ' : '') . ($iGet ? 'You get from ' . count(array_filter($mine, fn($p) => $p['to'] === $user_id)) : ''));
  shared_panel('Settle up', 'send', rtrim($meta, ' ·'), $mine ? (string) count($mine) : '', 'orange');
?>
  <?php if (!$mine): ?><p class="k" style="padding:12px 0 4px">You have nothing to pay or receive here.</p><?php endif; ?>
  <?php foreach ($mine as $p) { echo $debtRow($p); } ?>
  <?php if ($others): ?>
  <details class="sg-d"><summary>Between other members (<?= count($others) ?>)</summary>
    <div class="sg-body"><?php foreach ($others as $p) { echo $debtRow($p); } ?></div>
  </details>
  <?php endif; ?>
<?php shared_panel_end(); ?>
<?php elseif ($expenses || $settlements): ?>
<p class="k" style="margin:0 4px 14px">Everyone is settled up. What you owe each other adds up to zero, so nothing is left to pay.</p>
<?php endif; ?>

<?php
$firstTot = $totals ? shared_money((int) $totals[0]['total'], $totals[0]['currency']) : '';
shared_panel('Expenses', 'wal', $expenses ? count($expenses) . ' expense' . (count($expenses) === 1 ? '' : 's') : 'No expenses yet', (string) count($expenses));
?>
  <?php if (!$expenses): ?><p class="k" style="padding:12px 0 4px">No expenses yet. Tap "Add expense" and choose how to split it.</p><?php endif; ?>
  <?php foreach ($expenses as $x):
    $cur = $x['currency']; $mineShare = $x['my_share'] !== null ? (int) $x['my_share'] : null;
    $paidMe = (int) $x['paid_by'] === $user_id; $total = (int) $x['amount_minor'];
    if ($paidMe) { $net = $total - (int) ($mineShare ?? 0); $label = $net > 0 ? 'you lent' : 'you paid'; $cls = 'tg'; }
    elseif ($mineShare) { $net = $mineShare; $label = 'you owe'; $cls = 'tx'; }
    else { $net = 0; $label = 'not involved'; $cls = 'tn'; }
    $can = shared_can_edit($x, $group, $user_id);
    $xc = (ef_feature_on('comments') && function_exists('comments_add')) ? ($cmts[(int) $x['id']] ?? []) : null;
    $open = $xc && isset($_GET['c']) && (int) $_GET['c'] === (int) $x['id'];
  ?>
  <div class="row sg-x" id="x<?= (int) $x['id'] ?>">
    <div class="sg-xh"><span class="sg-xm"><b><?= e($x['title']) ?></b><?php if (!empty($rcpts[(int) $x['id']])): ?> <a href="<?= e(ef_url('receipts/view.php?id=' . (int) $rcpts[(int) $x['id']])) ?>" target="_blank" rel="noopener" title="View receipt" style="color:var(--a1)"><?= ef_icon('rcp') ?></a><?php endif; ?>
      <small><?= e($x['payer_name'] ?? 'Former member') ?> paid <?= shared_money($total, $cur) ?> · <?= e(date('d M Y', strtotime($x['expense_date']))) ?></small></span>
    <span class="tag <?= $cls ?>"><?= e($label) ?><?= $net > 0 ? ' ' . shared_money($net, $cur) : '' ?></span></div>
      <details class="sg-d"<?= $open ? ' open' : '' ?>><summary>Details<?= $xc ? ' · ' . count($xc) . ' comment' . (count($xc) === 1 ? '' : 's') : '' ?></summary>
        <div class="sg-body">
          <div><div class="k">Split: <?= e(SHARED_SPLIT_TYPES[$x['split_type']] ?? 'Split') ?></div>
            <?php foreach ($splitNames[(int) $x['id']] ?? [] as [$sn, $sh]): ?><div class="sg-ln"><span><?= e($sn) ?></span><b><?= shared_money($sh, $cur) ?></b></div><?php endforeach; ?></div>
          <?php if (!empty($x['note'])): ?><div class="k">Note: <?= e($x['note']) ?></div><?php endif; ?>
          <?php if ($xc !== null): ?>
          <div>
            <div class="k"><?= ef_icon('msg') ?> Comments<?= $xc ? ' (' . count($xc) . ')' : '' ?></div>
            <?php foreach ($xc as $c): ?>
            <small style="display:flex;gap:8px;align-items:flex-start;margin:6px 0"><span style="flex:1;overflow-wrap:anywhere"><b><?= (int) $c['user_id'] === $user_id ? 'You' : e($c['name'] ?? 'Former member') ?></b> · <?= function_exists('activity_ago') ? e(activity_ago((int) $c['age_s'], $c['created_at'])) : '' ?><br><?= nl2br(e($c['body'])) ?></span>
              <?php if ((int) $c['user_id'] === $user_id || $isOwner): ?><a class="del" href="<?= e(ef_url('comments/action.php')) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>,"comment_id":<?= (int) $c['id'] ?>}' onclick="return confirm('Delete this comment?')" aria-label="Delete comment"><?= ef_icon('tr') ?></a><?php endif; ?></small>
            <?php endforeach; ?>
            <form method="POST" action="<?= e(ef_url('comments/action.php')) ?>" autocomplete="off" style="display:flex;gap:8px;margin-top:6px">
              <?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="expense_id" value="<?= (int) $x['id'] ?>">
              <input class="inp sg-cin" type="text" name="body" maxlength="500" placeholder="Write a comment…" aria-label="Comment" required>
              <button class="btn sm" type="submit">Send</button>
            </form>
          </div>
          <?php endif; ?>
          <?php if ($can): ?>
          <div class="sg-acts">
            <a class="btn sm o" href="<?= e(ef_url('shared/add.php?edit=' . (int) $x['id'])) ?>"><?= ef_icon('pen') ?> Edit</a>
            <a class="btn sm o sg-del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_expense","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>}' onclick="return confirm('Delete this expense for everyone in the group?')"><?= ef_icon('tr') ?> Delete</a>
          </div>
          <?php endif; ?>
        </div>
      </details>
  </div>
  <?php endforeach; ?>
<?php shared_panel_end(); ?>

<?php if ($settlements): ?>
<?php $lastSt = $settlements[0]; shared_sec('grp-payments', 'Payments made', 'ok', 'blue', false, 'Last: ' . ($lastSt['from_name'] ?? 'Former member') . ' paid ' . ($lastSt['to_name'] ?? 'Former member') . ' ' . shared_money((int) $lastSt['amount_minor'], $lastSt['currency']), (string) count($settlements)); ?>
  <?php foreach ($settlements as $st): $canDel = (int) $st['created_by'] === $user_id || $isOwner; ?>
  <div class="row">
    <span><?= e($st['from_name'] ?? 'Former member') ?> paid <?= e($st['to_name'] ?? 'Former member') ?><small><?= e(date('d M Y', strtotime($st['settled_on']))) ?><?= $st['note'] ? ' · ' . e($st['note']) : '' ?></small></span>
    <b><?= shared_money((int) $st['amount_minor'], $st['currency']) ?></b>
    <?php if ($canDel): ?><span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_settlement","group_id":<?= $gid ?>,"settlement_id":<?= (int) $st['id'] ?>}' onclick="return confirm('Delete this payment record? Balances will change.')" aria-label="Delete payment"><?= ef_icon('tr') ?></a></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php shared_sec_end(); ?>
<?php endif; ?>

<?php if ($nets): ?>
<?php shared_sec('grp-balance', "Everyone's balance", 'users', 'blue', false, 'Who paid how much and who gets back'); ?>
    <?php foreach ($nets as $cur => $byUser): ksort($byUser); ?>
      <div class="k" style="margin-top:6px"><?= e($cur) ?></div>
      <?php foreach ($byUser as $uid => $n): $pt = $parts[$cur][$uid]; ?>
      <div class="row"><span><?= $uid === $user_id ? 'You' : e($nm($uid)) ?><small>paid <?= shared_money($pt['paid'], $cur) ?> · share <?= shared_money($pt['share'], $cur) ?><?= ($pt['sent'] || $pt['recv']) ? ' · paid back ' . shared_money($pt['sent'], $cur) . ' · got back ' . shared_money($pt['recv'], $cur) : '' ?></small></span>
        <b class="<?= $n > 0 ? 'in' : ($n < 0 ? 'ex' : '') ?>"><?= $n > 0 ? 'gets ' : ($n < 0 ? 'owes ' : 'settled ') ?><?= $n === 0 ? '' : shared_money(abs($n), $cur) ?></b></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
<?php shared_sec_end(); ?>
<?php endif; ?>

<?php shared_sec('grp-members', 'Members', 'usr', 'blue', false, implode(', ', array_map(fn($m) => $m['name'], array_slice($members, 0, 4))) . (count($members) > 4 ? ' +' . (count($members) - 4) : ''), (string) count($members)); ?>
    <?php foreach ($members as $i => $m): ?>
    <div class="row">
      <?= shared_avatar($m['name'], $i) ?>
      <span><?= e($m['name']) ?><?= (int) $m['id'] === $user_id ? ' (you)' : '' ?><small><?= $m['role'] === 'owner' ? 'Owner' : 'Member' ?> · joined <?= e(date('d M Y', strtotime($m['joined_at']))) ?></small></span>
      <?php if ($isOwner && (int) $m['id'] !== $user_id): ?>
      <span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"remove","group_id":<?= $gid ?>,"user_id":<?= (int) $m['id'] ?>}' onclick="return confirm('Remove <?= e(addslashes($m['name'])) ?> from this group?')" aria-label="Remove <?= e($m['name']) ?>"><?= ef_icon('tr') ?></a></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php shared_sec_end(); ?>
<?php shared_sec('grp-invite', 'Invite friends', 'link', 'blue', count($members) === 1, 'Share the link on WhatsApp or copy it'); ?>
    <div class="fl"><label for="invite">Invite link</label><input class="inp" id="invite" type="text" value="<?= e($invite) ?>" readonly onclick="this.select()"></div>
    <div class="sg-acts">
      <button class="btn" type="button" id="copy-invite"><?= ef_icon('link') ?> Copy link</button>
      <a class="btn i2" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= ef_icon('msg') ?> WhatsApp</a>
    </div>
    <p class="k" style="margin-top:10px">Anyone with this link can join after signing in.<?= $isOwner ? ' Made a mistake or shared it with the wrong person? Create a new link and the old one stops working.' : '' ?></p>
    <?php if ($isOwner): ?>
    <a class="btn sm o" style="margin-top:8px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"regen","group_id":<?= $gid ?>}' onclick="return confirm('Create a new link? The old link will stop working.')">Create new link</a>
    <?php endif; ?>
<?php shared_sec_end(); ?>

<?php shared_sec('grp-settings', $isOwner ? 'Group settings' : 'Leave group', $isOwner ? 'set' : 'out', 'blue', false, $isOwner ? 'Rename, change icon, delete the group' : 'Stop seeing this group'); ?>
    <?php if ($isOwner): ?>
    <form method="POST" action="<?= e($action) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
      <div class="fl"><label for="gname">Group name</label><input class="inp" id="gname" type="text" name="name" maxlength="60" value="<?= e($group['name']) ?>" required></div>
      <div class="fl"><label>Icon</label>
        <div class="chips">
          <?php foreach (shared_icons() as $key => $label): ?>
          <label><input type="radio" name="icon" value="<?= e($key) ?>"<?= $group['icon'] === $key ? ' checked' : '' ?>><span class="chip"><?= ef_icon($key) ?><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <button class="btn" type="submit">Save changes</button>
    </form>
    <div class="sg-danger">
      <b><?= ef_icon('warn', '#ea580c') ?> Delete group</b>
      <p class="k">Deletes the group, its expenses and payments for everyone. This cannot be undone.</p>
      <a class="btn sm o sg-del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>}' onclick="return confirm('Delete this group for everyone?')">Delete group</a>
    </div>
    <?php else: ?>
    <p class="k">You can leave once you owe nothing and are owed nothing. Your past expenses stay in the group for the others.</p>
    <a class="btn sm o sg-del" style="margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"leave","group_id":<?= $gid ?>}' onclick="return confirm('Leave this group?')">Leave group</a>
    <?php endif; ?>
<?php shared_sec_end(); ?>
<?php
$js = shared_sec_js() . '<script>(function(){var b=document.getElementById("copy-invite"),i=document.getElementById("invite");if(!b)return;b.addEventListener("click",function(){i.select();var done=function(){b.lastChild.textContent=" Copied!";setTimeout(function(){b.lastChild.textContent=" Copy link"},1800)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done()})}else{document.execCommand("copy");done()}})})()</script>';
ef_page_end(['mode' => 's', 'active' => 'grp', 'scripts' => $js]);
