<?php
/** Add (or edit) a shared expense: group, who paid, total, and how it is split. */
require_once __DIR__ . '/../modules/shared/boot.php';

$groups = shared_groups_of($conn, $user_id);
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$gid = filter_var($_POST['group_id'] ?? ($_GET['group'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$existing = null;
if ($editId) {
    // The expense decides the group; the group check below proves the user is a member.
    $q = $conn->prepare('SELECT group_id FROM shared_expenses WHERE id = ?');
    $q->execute([$editId]);
    $gid = (int) $q->fetchColumn();
}
if (!$gid && count($groups) === 1) {
    $gid = (int) $groups[0]['id'];
}
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if ($editId) {
    $existing = $group ? shared_expense_get($conn, $editId, $gid) : null;
    if (!$existing || !shared_can_edit($existing, $group, $user_id)) {
        $_SESSION['error'] = 'Expense not found, or you cannot change it.';
        shared_back($group ? 'shared/group.php?id=' . $gid : 'shared/groups.php');
    }
}
$members = $group ? shared_members_of($conn, $gid) : [];
$memberIds = array_map(fn($m) => (int) $m['id'], $members);
$isCreator = !$existing || (int) $existing['created_by'] === $user_id;

$cats = ['Food', 'Transport', 'Shopping', 'Entertainment', 'Bills', 'Healthcare', 'Education', 'Other'];
$old = [
    'title' => '', 'amount' => '', 'currency' => null, 'paid_by' => (string) $user_id, 'split_type' => 'equal',
    'expense_date' => date('Y-m-d'), 'note' => '', 'personal' => '', 'category' => 'Food',
    'part' => array_fill_keys(array_map('strval', $memberIds), '1'), 'val' => [],
];
if ($existing) {
    $sp = shared_splits_of($conn, $editId);
    $old['title'] = $existing['title'];
    $old['amount'] = shared_from_minor((int) $existing['amount_minor']);
    $old['currency'] = $existing['currency'];
    $old['paid_by'] = (string) $existing['paid_by'];
    $old['split_type'] = $existing['split_type'];
    $old['expense_date'] = $existing['expense_date'];
    $old['note'] = (string) ($existing['note'] ?? '');
    $old['personal'] = $existing['personal_expense_id'] ? '1' : '';
    $old['part'] = [];
    foreach ($sp as $uid => $row) {
        $old['part'][(string) $uid] = '1';
        if ($existing['split_type'] === 'exact') {
            $old['val'][(string) $uid] = shared_from_minor($row['share_minor']);
        } elseif ($row['weight'] !== null) {
            $old['val'][(string) $uid] = rtrim(rtrim((string) $row['weight'], '0'), '.');
        }
    }
}

if ($old['currency'] === null) {
    // New expense: start from the currency the group used last, otherwise PKR.
    $lc = $group ? $conn->prepare('SELECT currency FROM shared_expenses WHERE group_id = ? ORDER BY id DESC LIMIT 1') : null;
    if ($lc) {
        $lc->execute([$gid]);
    }
    $old['currency'] = ($lc ? $lc->fetchColumn() : false) ?: 'PKR';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['title', 'amount', 'currency', 'paid_by', 'split_type', 'expense_date', 'note', 'personal', 'category'] as $f) {
        $v = $_POST[$f] ?? $old[$f];
        $old[$f] = is_string($v) ? $v : $old[$f];
    }
    $old['personal'] = ($_POST['personal'] ?? '') === '1' ? '1' : ''; // an unticked box is not sent at all
    $old['part'] = [];
    foreach ((array) ($_POST['part'] ?? []) as $uid => $v) {
        if (is_scalar($v) && ctype_digit((string) $uid)) {
            $old['part'][(string) $uid] = '1';
        }
    }
    $old['val'] = [];
    foreach ((array) ($_POST['val'] ?? []) as $uid => $v) {
        if (is_string($v) && ctype_digit((string) $uid)) {
            $old['val'][(string) $uid] = trim($v);
        }
    }

    $title = clean_text($old['title'], 100);
    $amount = parse_amount($old['amount']);
    $currency = normalize_currency($old['currency']);
    $date = valid_date($old['expense_date']);
    $payer = ctype_digit($old['paid_by']) ? (int) $old['paid_by'] : 0;
    $note = trim($old['note']) === '' ? null : clean_text($old['note'], 255);
    $type = $old['split_type'];
    $parts = array_values(array_filter(array_map('intval', array_keys($old['part'])), fn($i) => in_array($i, $memberIds, true)));
    $key = rl_key((string) $user_id);
    $ft = (string) ($_POST['ft'] ?? '');

    if (!$group) {
        $error = 'Choose a group first.';
    } elseif (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!shared_token_valid($ft)) {
        // Already saved (double tap or refresh): go to the group instead of saving twice.
        shared_back('shared/group.php?id=' . $gid);
    } elseif (!$existing && rl_blocked($conn, 'shared_expense', $key, SHARED_MAX_EXPENSES_PER_HOUR, 3600)) {
        $error = 'You added many expenses just now. Please try again later.';
    } elseif ($title === null) {
        $error = 'Please enter a description (up to 100 characters).';
    } elseif ($amount === null) {
        $error = 'Please enter a valid total amount greater than zero.';
    } elseif ($currency === null) {
        $error = 'Please choose a supported currency.';
    } elseif ($date === null || $date > date('Y-m-d', strtotime('+1 day'))) {
        $error = 'Please choose a valid date (not in the future).';
    } elseif (!in_array($payer, $memberIds, true)) {
        $error = 'The person who paid must be a member of the group.';
    } elseif (!isset(SHARED_SPLIT_TYPES[$type])) {
        $error = 'Please choose how to split.';
    } elseif (trim($old['note']) !== '' && $note === null) {
        $error = 'The note is too long (255 characters at most).';
    } elseif ($old['personal'] === '1' && !in_array($old['category'], $cats, true)) {
        $error = 'Please choose a category for your Personal expense.';
    } else {
        $totalMinor = (int) round($amount * 100);
        $inputs = [];
        foreach ($parts as $uid) {
            $inputs[$uid] = $old['val'][(string) $uid] ?? '';
        }
        $res = shared_compute_split($totalMinor, $type, $parts, $inputs);
        if (is_string($res)) {
            $error = $res;
        } else {
            try {
                $personal = null;
                if ($isCreator) {
                    $personal = $old['personal'] === '1' ? $old['category'] : false;
                }
                shared_token_use($ft);
                $id = shared_expense_save($conn, $group, $user_id, [
                    'title' => $title, 'amount_minor' => $totalMinor, 'currency' => $currency, 'paid_by' => $payer,
                    'split_type' => $type, 'expense_date' => $date, 'note' => $note,
                    'shares' => $res['shares'], 'weights' => $res['weights'],
                ], $personal, $existing);
                if (!$existing) {
                    rl_hit($conn, 'shared_expense', $key);
                }
                shared_emit($conn, $gid, $user_id, $existing ? 'expense_edit' : 'expense_add', ['title' => $title, 'amount_minor' => $totalMinor, 'currency' => $currency], array_merge(array_keys($res['shares']), [$payer]));
                logAction($conn, $user_id, $existing ? 'shared_edit_expense' : 'shared_add_expense', "Group ID: $gid, expense ID: $id");
                $_SESSION['success'] = $existing ? 'Expense updated.' : 'Expense added and split.';
                shared_back('shared/group.php?id=' . $gid);
            } catch (Throwable $e) {
                error_log('ExpenseFlow shared expense save failed: ' . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

$csrf = csrf_token();
$ftoken = ($_SERVER['REQUEST_METHOD'] === 'POST' && shared_token_valid($_POST['ft'] ?? null)) ? (string) $_POST['ft'] : shared_token_new();
ef_page_start(['title' => $existing ? 'Edit shared expense' : 'Add shared expense', 'sub' => $group ? $group['name'] : 'Split a bill with your group', 'mode' => 's', 'active' => 'sadd', 'user' => $user]);
?>
<div class="g" style="max-width:720px;margin:0 auto;padding:22px">
<?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$groups): ?>
  <p class="k" style="padding:12px 0">You need a group first. <a href="<?= e(ef_url('shared/groups.php')) ?>" style="color:var(--a1);font-weight:700">Create or join a group</a>, then come back to split a bill.</p>
<?php elseif (!$group): ?>
  <div class="t">Which group?</div>
  <div class="chips">
    <?php foreach ($groups as $gr): ?>
    <a class="chip" href="<?= e(ef_url('shared/add.php?group=' . (int) $gr['id'])) ?>"><?= ef_icon(shared_icon_key($gr['icon'])) ?><?= e($gr['name']) ?></a>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <?php if (!$existing && count($groups) > 1): ?>
  <div class="fl"><label>Group</label>
    <div class="chips">
      <?php foreach ($groups as $gr): ?>
      <a class="chip<?= (int) $gr['id'] === $gid ? ' on' : '' ?>" href="<?= e(ef_url('shared/add.php?group=' . (int) $gr['id'])) ?>"><?= ef_icon(shared_icon_key($gr['icon'])) ?><?= e($gr['name']) ?></a>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>
  <form method="POST" autocomplete="off" id="sx" data-type="<?= e($old['split_type']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="group_id" value="<?= $gid ?>"><input type="hidden" name="ft" value="<?= e($ftoken) ?>">
    <div class="fl"><label for="title">Description</label>
      <input class="inp" id="title" type="text" name="title" maxlength="100" value="<?= e($old['title']) ?>" placeholder="e.g. Dinner, Hotel, Petrol" required></div>
    <div class="fl"><label for="amount">Total amount</label>
      <input class="inp amt" id="amount" type="number" inputmode="decimal" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" placeholder="0.00" required></div>
    <div class="fl"><label for="currency">Currency</label>
      <select class="inp" id="currency" name="currency" required><?= currency_select_options($old['currency']) ?></select></div>
    <div class="fl"><label for="paid_by">Paid by</label>
      <select class="inp" id="paid_by" name="paid_by">
        <?php foreach ($members as $m): ?>
        <option value="<?= (int) $m['id'] ?>"<?= (string) $m['id'] === $old['paid_by'] ? ' selected' : '' ?>><?= e($m['name']) ?><?= (int) $m['id'] === $user_id ? ' (you)' : '' ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="fl"><label for="expense_date">Date</label>
      <input class="inp" id="expense_date" type="date" name="expense_date" value="<?= e($old['expense_date']) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required></div>

    <div class="fl"><label>Split</label>
      <div class="chips">
        <?php foreach (SHARED_SPLIT_TYPES as $k => $label): ?>
        <label><input type="radio" name="split_type" value="<?= e($k) ?>"<?= $old['split_type'] === $k ? ' checked' : '' ?>><span class="chip"><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div></div>

    <div class="fl"><label>Split between</label>
      <?php foreach ($members as $i => $m): $uid = (int) $m['id']; ?>
      <div class="row" style="gap:12px">
        <label style="display:flex;align-items:center;gap:10px;flex:1;min-width:0;text-transform:none;letter-spacing:0;font-size:.85rem;color:var(--tx);cursor:pointer">
          <input type="checkbox" name="part[<?= $uid ?>]" value="1" class="pc"<?= isset($old['part'][(string) $uid]) ? ' checked' : '' ?>>
          <?= shared_avatar($m['name'], $i) ?><span style="overflow-wrap:anywhere"><?= e($m['name']) ?><?= $uid === $user_id ? ' (you)' : '' ?></span>
        </label>
        <span class="vw" style="display:flex;align-items:center;gap:6px">
          <input class="inp vi" style="width:104px;padding:8px 10px" type="text" inputmode="decimal" name="val[<?= $uid ?>]" value="<?= e($old['val'][(string) $uid] ?? '') ?>" aria-label="Value for <?= e($m['name']) ?>">
          <small class="vu k" style="min-width:22px"></small>
        </span>
        <b class="pv k" style="min-width:84px;text-align:right"></b>
      </div>
      <?php endforeach; ?>
      <p class="k" id="sum" style="margin-top:8px" aria-live="polite"></p>
    </div>

    <div class="fl"><label for="note">Note (optional)</label>
      <input class="inp" id="note" type="text" name="note" maxlength="255" value="<?= e($old['note']) ?>"></div>

    <?php if ($isCreator): ?>
    <div class="fl">
      <label class="row" style="border:0;padding:0;text-transform:none;letter-spacing:0;font-size:.88rem;color:var(--tx);cursor:pointer;gap:12px">
        <span style="flex:1">Add my share to Personal expenses<small>Saves only your share as an expense in your Personal account.</small></span>
        <input type="checkbox" class="swc" id="personal" name="personal" value="1"<?= $old['personal'] === '1' ? ' checked' : '' ?>><span class="sw"></span>
      </label>
      <div id="catbox" style="margin-top:8px"><select class="inp" name="category" aria-label="Personal category">
        <?php foreach ($cats as $c): ?><option<?= $old['category'] === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <?php endif; ?>

    <button class="btn" type="submit" style="width:100%;padding:14px"><?= $existing ? 'Save changes' : 'Add expense' ?></button>
    <a class="btn o" style="width:100%;margin-top:8px;text-align:center" href="<?= e(ef_url('shared/group.php?id=' . $gid)) ?>">Cancel</a>
  </form>
<?php endif; ?>
</div>
<?php
$js = <<<'JS'
<script>(function(){var f=document.getElementById("sx");if(!f)return;
var amt=f.amount,sum=document.getElementById("sum"),rows=[].slice.call(f.querySelectorAll(".row")).filter(function(r){return r.querySelector(".pc")});
var cur=function(){return (f.querySelector('input[name=split_type]:checked')||{}).value||"equal"};
var num=function(v){v=parseFloat(String(v).replace(",","."));return isFinite(v)&&v>0?v:0};
function upd(){var t=cur(),total=Math.round(num(amt.value)*100),sel=rows.filter(function(r){return r.querySelector(".pc").checked}),n=sel.length,acc=0,tot=0;
rows.forEach(function(r){var on=r.querySelector(".pc").checked,vi=r.querySelector(".vi"),vw=r.querySelector(".vw"),vu=r.querySelector(".vu"),pv=r.querySelector(".pv");
vw.style.display=(t==="equal"||!on)?"none":"flex";vu.textContent=t==="percent"?"%":(t==="shares"?"sh":"");vi.placeholder=t==="exact"?"0.00":(t==="percent"?"0":"1");
if(on&&t!=="equal"){var v=num(vi.value);acc+=t==="exact"?Math.round(v*100):Math.round(v*100)}});
var base=0;sel.forEach(function(r){base+=Math.round(num(r.querySelector(".vi").value)*100)});
rows.forEach(function(r){var on=r.querySelector(".pc").checked,pv=r.querySelector(".pv"),v=Math.round(num(r.querySelector(".vi").value)*100),s="";
if(on&&total>0){if(t==="equal"&&n)s=(total/n/100).toFixed(2);else if(t==="exact")s=(v/100).toFixed(2);else if(t==="percent")s=(total*v/10000/100).toFixed(2);else if(t==="shares"&&base>0)s=(total*v/base/100).toFixed(2)}pv.textContent=s?"≈ "+s:""});
if(t==="exact"){var left=total-base;sum.textContent=total?(left===0?"Amounts add up.":(left>0?(left/100).toFixed(2)+" left to assign.":(-left/100).toFixed(2)+" too much.")):""}
else if(t==="percent"){sum.textContent=(base/100).toFixed(2)+"% of 100%"}else{sum.textContent=n?n+" selected":"Choose at least one person."}}
f.addEventListener("submit",function(){var b=f.querySelector('button[type=submit]');if(b){setTimeout(function(){b.disabled=true},0)}});f.addEventListener("input",upd);f.addEventListener("change",upd);
var p=document.getElementById("personal"),cb=document.getElementById("catbox");if(p&&cb){var t2=function(){cb.style.display=p.checked?"block":"none"};p.addEventListener("change",t2);t2()}
upd()})()</script>
JS;
ef_page_end(['mode' => 's', 'active' => 'sadd', 'scripts' => $js]);
