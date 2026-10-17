<?php
/** Record a payment between two group members (settle up). */
require_once __DIR__ . '/../modules/shared/boot.php';

$gid = filter_var($_POST['group_id'] ?? ($_GET['group'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$members = shared_members_of($conn, $gid);
$memberIds = array_map(fn($m) => (int) $m['id'], $members);
$isOwner = $group['my_role'] === 'owner';

$pick = fn($k) => (string) ($_POST[$k] ?? $_GET[$k] ?? '');
$old = [
    'from' => $pick('from') !== '' ? $pick('from') : (string) $user_id,
    'to' => $pick('to'),
    'amount' => $pick('amount'),
    'currency' => $pick('currency') !== '' ? $pick('currency') : null,
    'settled_on' => $pick('settled_on') !== '' ? $pick('settled_on') : date('Y-m-d'),
    'note' => (string) ($_POST['note'] ?? ''),
];
if ($old['currency'] === null) {
    $lc = $conn->prepare('SELECT currency FROM shared_expenses WHERE group_id = ? ORDER BY id DESC LIMIT 1');
    $lc->execute([$gid]);
    $old['currency'] = $lc->fetchColumn() ?: 'PKR';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from = ctype_digit($old['from']) ? (int) $old['from'] : 0;
    $to = ctype_digit($old['to']) ? (int) $old['to'] : 0;
    $amount = parse_amount($old['amount']);
    $currency = normalize_currency($old['currency']);
    $date = valid_date($old['settled_on']);
    $note = trim($old['note']) === '' ? null : clean_text($old['note'], 255);
    $key = rl_key((string) $user_id);

    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (rl_blocked($conn, 'shared_settle', $key, 60, 3600)) {
        $error = 'You recorded many payments just now. Please try again later.';
    } elseif (!in_array($from, $memberIds, true) || !in_array($to, $memberIds, true) || $from === $to) {
        $error = 'Choose two different members of this group.';
    } elseif ($from !== $user_id && $to !== $user_id && !$isOwner) {
        $error = 'You can only record payments that you made or received.';
    } elseif ($amount === null) {
        $error = 'Please enter a valid amount greater than zero.';
    } elseif ($currency === null) {
        $error = 'Please choose a supported currency.';
    } elseif ($date === null || $date > date('Y-m-d', strtotime('+1 day'))) {
        $error = 'Please choose a valid date (not in the future).';
    } elseif (trim($old['note']) !== '' && $note === null) {
        $error = 'The note is too long (255 characters at most).';
    } else {
        $conn->prepare('INSERT INTO shared_settlements (group_id,from_user,to_user,amount_minor,currency,settled_on,note,created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$gid, $from, $to, (int) round($amount * 100), $currency, $date, $note, $user_id]);
        rl_hit($conn, 'shared_settle', $key);
        logAction($conn, $user_id, 'shared_settle', "Group ID: $gid, $from -> $to, $amount $currency");
        $_SESSION['success'] = 'Payment recorded. Balances updated.';
        shared_back('shared/group.php?id=' . $gid);
    }
}

ef_page_start(['title' => 'Settle up', 'sub' => $group['name'], 'mode' => 's', 'active' => 'grp', 'user' => $user]);
$opts = function (string $sel) use ($members, $user_id) {
    $h = '';
    foreach ($members as $m) {
        $h .= '<option value="' . (int) $m['id'] . '"' . ((string) $m['id'] === $sel ? ' selected' : '') . '>' . e($m['name']) . ((int) $m['id'] === $user_id ? ' (you)' : '') . '</option>';
    }
    return $h;
};
?>
<div class="g" style="max-width:560px;margin:0 auto;padding:22px">
  <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="POST" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="group_id" value="<?= $gid ?>">
    <div class="fl"><label for="from">Who paid</label><select class="inp" id="from" name="from"><?= $opts($old['from']) ?></select></div>
    <div class="fl"><label for="to">Paid to</label><select class="inp" id="to" name="to"><option value="">Choose…</option><?= $opts($old['to']) ?></select></div>
    <div class="fl"><label for="amount">Amount</label>
      <input class="inp amt" id="amount" type="number" inputmode="decimal" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" placeholder="0.00" required></div>
    <div class="fl"><label for="currency">Currency</label><select class="inp" id="currency" name="currency" required><?= currency_select_options($old['currency']) ?></select></div>
    <div class="fl"><label for="settled_on">Date</label><input class="inp" id="settled_on" type="date" name="settled_on" value="<?= e($old['settled_on']) ?>" max="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required></div>
    <div class="fl"><label for="note">Note (optional)</label><input class="inp" id="note" type="text" name="note" maxlength="255" value="<?= e($old['note']) ?>" placeholder="e.g. Cash, Easypaisa"></div>
    <p class="k" style="margin-bottom:12px">This only records a payment that already happened. No money moves through the app.</p>
    <button class="btn" type="submit" style="width:100%;padding:14px">Record payment</button>
    <a class="btn o" style="width:100%;margin-top:8px;text-align:center" href="<?= e(ef_url('shared/group.php?id=' . $gid)) ?>">Cancel</a>
  </form>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'grp']);
