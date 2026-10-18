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
$cmts = function_exists('comments_for_expenses') ? comments_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$rcpts = function_exists('receipts_for_expenses') ? receipts_for_expenses($conn, array_map(fn($x) => (int) $x['id'], $expenses)) : [];
$splitNames = [];
if ($expenses) {
    $ids = array_map(fn($x) => (int) $x['id'], $expenses);
    $q = $conn->query('SELECT s.expense_id, s.share_minor, u.name FROM shared_splits s LEFT JOIN users u ON u.id = s.user_id WHERE s.expense_id IN (' . implode(',', $ids) . ') ORDER BY s.user_id');
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $splitNames[(int) $r['expense_id']][] = [$r['name'] ?? 'Former member', (int) $r['share_minor']];
    }
}

ef_page_start(['title' => $group['name'], 'sub' => count($members) . ' member' . (count($members) === 1 ? '' : 's'), 'mode' => 's', 'active' => 'grp', 'user' => $user]);
?>
<div class="g card hero">
  <div class="gh">
    <div class="gi" style="margin:0"><?= ef_icon(shared_icon_key($group['icon']), '#1d4ed8') ?></div>
    <div style="flex:1"><b style="font-size:1.1rem"><?= e($group['name']) ?></b>
      <div class="stk" style="margin-top:6px"><?php foreach (array_slice($members, 0, 6) as $i => $m) { echo shared_avatar($m['name'], $i); } ?></div></div>
    <div style="text-align:right"><div class="k">Total spent</div>
      <?php if ($totals): foreach (array_slice($totals, 0, 3) as $t): ?><div class="v" style="margin:0;font-size:1.15rem"><?= shared_money((int) $t['total'], $t['currency']) ?></div><?php endforeach; else: ?><div class="v" style="margin:0">0.00</div><?php endif; ?></div>
  </div>
</div>

<?php
$debts = [];
foreach ($pairs as $cur => $list) {
    foreach ($list as $p) { $p['currency'] = $cur; $debts[] = $p; }
}
usort($debts, fn($x, $y) => (($y['from'] === $user_id || $y['to'] === $user_id) <=> ($x['from'] === $user_id || $x['to'] === $user_id)));
?>
<div class="G2" style="margin-bottom:14px">
  <div class="g card">
    <div class="t">Who pays whom</div>
    <?php if (!$debts): ?><p class="k" style="padding:10px 0"><?= $expenses || $settlements ? 'Everyone is settled up. What you owe each other adds up to zero, so nothing is left to pay.' : 'Add an expense to see who owes what.' ?></p><?php endif; ?>
    <?php foreach ($debts as $p):
      $mineFrom = $p['from'] === $user_id; $mineTo = $p['to'] === $user_id;
      $url = ef_url('shared/settle.php?' . http_build_query(['group' => $gid, 'from' => $p['from'], 'to' => $p['to'], 'amount' => shared_from_minor($p['amount']), 'currency' => $p['currency']]));
    ?>
    <div class="row">
      <span><?= $mineFrom ? '<b>You</b> pay' : e($nm($p['from'])) . ' pays' ?> <?= $mineTo ? '<b>you</b>' : e($nm($p['to'])) ?><small><?= $mineFrom ? 'you owe' : ($mineTo ? 'you get' : 'between others') ?></small></span>
      <b class="<?= $mineFrom ? 'ex' : ($mineTo ? 'in' : '') ?>"><?= shared_money($p['amount'], $p['currency']) ?></b>
      <?php if ($mineFrom || $mineTo || $isOwner): ?><a class="btn sm o" href="<?= e($url) ?>">Settle</a><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <a class="btn sm" style="margin-top:10px" href="<?= e(ef_url('shared/settle.php?group=' . $gid)) ?>">Record a payment</a>
  </div>
  <div class="g card">
    <div class="t">Net per person</div>
    <?php if (!$nets): ?><p class="k" style="padding:10px 0">No balances yet.</p><?php endif; ?>
    <?php foreach ($nets as $cur => $byUser): ksort($byUser); ?>
      <div class="k" style="margin-top:6px"><?= e($cur) ?></div>
      <?php foreach ($byUser as $uid => $n): $pt = $parts[$cur][$uid]; ?>
      <div class="row"><span><?= $uid === $user_id ? 'You' : e($nm($uid)) ?><small>paid <?= shared_money($pt['paid'], $cur) ?> · share <?= shared_money($pt['share'], $cur) ?><?= ($pt['sent'] || $pt['recv']) ? ' · paid back ' . shared_money($pt['sent'], $cur) . ' · got back ' . shared_money($pt['recv'], $cur) : '' ?></small></span>
        <b class="<?= $n > 0 ? 'in' : ($n < 0 ? 'ex' : '') ?>"><?= $n > 0 ? 'gets ' : ($n < 0 ? 'owes ' : 'settled ') ?><?= $n === 0 ? '' : shared_money(abs($n), $cur) ?></b></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<div class="g card" style="margin-bottom:14px">
  <div class="t">Expenses <span class="k"><?= count($expenses) ?></span>
    <a class="btn sm" style="margin-left:auto" href="<?= e(ef_url('shared/add.php?group=' . $gid)) ?>"><?= ef_icon('pl') ?> Add expense</a></div>
  <?php if (!$expenses): ?><p class="k" style="padding:10px 0">No expenses yet. Add the first one and choose how to split it.</p><?php endif; ?>
  <?php foreach ($expenses as $x):
    $cur = $x['currency']; $mine = $x['my_share'] !== null ? (int) $x['my_share'] : null;
    $paidMe = (int) $x['paid_by'] === $user_id; $total = (int) $x['amount_minor'];
    if ($paidMe) { $net = $total - (int) ($mine ?? 0); $label = $net > 0 ? 'you lent' : 'you paid'; $cls = 'tg'; }
    elseif ($mine) { $net = $mine; $label = 'you owe'; $cls = 'tx'; }
    else { $net = 0; $label = 'not involved'; $cls = 'tn'; }
    $can = shared_can_edit($x, $group, $user_id);
  ?>
  <div class="row" id="x<?= (int) $x['id'] ?>" style="align-items:flex-start;flex-wrap:wrap">
    <span style="flex:1;min-width:0"><b><?= e($x['title']) ?></b><?php if (!empty($rcpts[(int) $x['id']])): ?> <a href="<?= e(ef_url('receipts/view.php?id=' . (int) $rcpts[(int) $x['id']])) ?>" target="_blank" rel="noopener" title="View receipt" style="color:var(--a1)"><?= ef_icon('rcp') ?></a><?php endif; ?>
      <small><?= e($x['payer_name'] ?? 'Former member') ?> paid <?= shared_money($total, $cur) ?> · <?= e(date('d M Y', strtotime($x['expense_date']))) ?> · <?= e(SHARED_SPLIT_TYPES[$x['split_type']] ?? 'Split') ?></small>
      <details style="margin-top:4px"><summary class="k" style="cursor:pointer">Who owes what</summary>
        <?php foreach ($splitNames[(int) $x['id']] ?? [] as [$nm, $sh]): ?><small><?= e($nm) ?>: <?= shared_money($sh, $cur) ?></small><?php endforeach; ?>
        <?php if (!empty($x['note'])): ?><small>Note: <?= e($x['note']) ?></small><?php endif; ?>
      </details>
      <?php if (function_exists('comments_add')): $xc = $cmts[(int) $x['id']] ?? []; ?>
      <details style="margin-top:4px"<?= $xc && isset($_GET['c']) && (int) $_GET['c'] === (int) $x['id'] ? ' open' : '' ?>><summary class="k" style="cursor:pointer"><?= ef_icon('msg') ?> Comments<?= $xc ? ' (' . count($xc) . ')' : '' ?></summary>
        <?php foreach ($xc as $c): ?>
        <small style="display:flex;gap:8px;align-items:flex-start;margin:6px 0"><span style="flex:1;overflow-wrap:anywhere"><b><?= (int) $c['user_id'] === $user_id ? 'You' : e($c['name'] ?? 'Former member') ?></b> · <?= function_exists('activity_ago') ? e(activity_ago((int) $c['age_s'], $c['created_at'])) : '' ?><br><?= nl2br(e($c['body'])) ?></span>
          <?php if ((int) $c['user_id'] === $user_id || $isOwner): ?><a class="del" href="<?= e(ef_url('comments/action.php')) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>,"comment_id":<?= (int) $c['id'] ?>}' onclick="return confirm('Delete this comment?')" aria-label="Delete comment"><?= ef_icon('tr') ?></a><?php endif; ?></small>
        <?php endforeach; ?>
        <form method="POST" action="<?= e(ef_url('comments/action.php')) ?>" autocomplete="off" style="display:flex;gap:8px;margin-top:6px">
          <?= csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="expense_id" value="<?= (int) $x['id'] ?>">
          <input class="inp" style="padding:8px 10px" type="text" name="body" maxlength="500" placeholder="Write a comment…" aria-label="Comment" required>
          <button class="btn sm" type="submit">Send</button>
        </form>
      </details>
      <?php endif; ?></span>
    <span class="tag <?= $cls ?>" style="white-space:nowrap"><?= e($label) ?><?= $net > 0 ? ' ' . shared_money($net, $cur) : '' ?></span>
    <?php if ($can): ?>
    <span class="acts">
      <a href="<?= e(ef_url('shared/add.php?edit=' . (int) $x['id'])) ?>" aria-label="Edit expense"><?= ef_icon('pen') ?></a>
      <a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_expense","group_id":<?= $gid ?>,"expense_id":<?= (int) $x['id'] ?>}' onclick="return confirm('Delete this expense for everyone in the group?')" aria-label="Delete expense"><?= ef_icon('tr') ?></a>
    </span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($settlements): ?>
<div class="g card" style="margin-bottom:14px">
  <div class="t">Payments <span class="k"><?= count($settlements) ?></span></div>
  <?php foreach ($settlements as $st): $canDel = (int) $st['created_by'] === $user_id || $isOwner; ?>
  <div class="row">
    <span><?= e($st['from_name'] ?? 'Former member') ?> paid <?= e($st['to_name'] ?? 'Former member') ?><small><?= e(date('d M Y', strtotime($st['settled_on']))) ?><?= $st['note'] ? ' · ' . e($st['note']) : '' ?></small></span>
    <b><?= shared_money((int) $st['amount_minor'], $st['currency']) ?></b>
    <?php if ($canDel): ?><span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete_settlement","group_id":<?= $gid ?>,"settlement_id":<?= (int) $st['id'] ?>}' onclick="return confirm('Delete this payment record? Balances will change.')" aria-label="Delete payment"><?= ef_icon('tr') ?></a></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="G2">
  <div class="g card">
    <div class="t">Members <span class="k"><?= count($members) ?></span></div>
    <?php foreach ($members as $i => $m): ?>
    <div class="row">
      <?= shared_avatar($m['name'], $i) ?>
      <span><?= e($m['name']) ?><?= (int) $m['id'] === $user_id ? ' (you)' : '' ?><small><?= $m['role'] === 'owner' ? 'Owner' : 'Member' ?> · joined <?= e(date('d M Y', strtotime($m['joined_at']))) ?></small></span>
      <?php if ($isOwner && (int) $m['id'] !== $user_id): ?>
      <span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"remove","group_id":<?= $gid ?>,"user_id":<?= (int) $m['id'] ?>}' onclick="return confirm('Remove <?= e(addslashes($m['name'])) ?> from this group?')" aria-label="Remove member"><?= ef_icon('tr') ?></a></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="g card">
      <div class="t">Invite friends</div>
      <div class="fl"><label for="invite">Invite link</label><input class="inp" id="invite" type="text" value="<?= e($invite) ?>" readonly onclick="this.select()"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="button" id="copy-invite"><?= ef_icon('link') ?> Copy link</button>
        <a class="btn i2" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= ef_icon('msg') ?> WhatsApp</a>
        <?php if ($isOwner): ?>
        <a class="btn o" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"regen","group_id":<?= $gid ?>}' onclick="return confirm('Create a new link? The old link will stop working.')">New link</a>
        <?php endif; ?>
      </div>
      <p class="k" style="margin-top:10px">Anyone with this link can join after signing in. Create a new link to stop old invites.</p>
    </div>

    <?php if ($isOwner): ?>
    <div class="g card">
      <div class="t">Group settings</div>
      <form method="POST" action="<?= e($action) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
        <div class="fl"><label for="gname">Name</label><input class="inp" id="gname" type="text" name="name" maxlength="60" value="<?= e($group['name']) ?>" required></div>
        <div class="fl"><label>Icon</label>
          <div class="chips">
            <?php foreach (shared_icons() as $key => $label): ?>
            <label><input type="radio" name="icon" value="<?= e($key) ?>"<?= $group['icon'] === $key ? ' checked' : '' ?>><span class="chip"><?= ef_icon($key) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <button class="btn" type="submit">Save</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="g card dz">
      <div class="t"><?= ef_icon('warn', '#ea580c') ?> <?= $isOwner ? 'Delete group' : 'Leave group' ?></div>
      <?php if ($isOwner): ?>
        <p class="k">Deletes the group for everyone. This cannot be undone.</p>
        <a class="btn o sm" style="color:#b91c1c;margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>}' onclick="return confirm('Delete this group for everyone?')">Delete group</a>
      <?php else: ?>
        <p class="k">You will no longer see this group.</p>
        <a class="btn o sm" style="color:#b91c1c;margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"leave","group_id":<?= $gid ?>}' onclick="return confirm('Leave this group?')">Leave group</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$js = '<script>(function(){var b=document.getElementById("copy-invite"),i=document.getElementById("invite");if(!b)return;b.addEventListener("click",function(){i.select();var done=function(){b.lastChild.textContent=" Copied!";setTimeout(function(){b.lastChild.textContent=" Copy link"},1800)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done()})}else{document.execCommand("copy");done()}})})()</script>';
ef_page_end(['mode' => 's', 'active' => 'grp', 'scripts' => $js]);
