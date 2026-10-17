<?php
/**
 * Randomised end-to-end check of the Shared balances against a database.
 * Usage: php tests/shared_ledger_test.php   (needs config/db.local.php pointing at a TEST database)
 * It creates its own users/groups and removes them again.
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
shared_ensure_schema($conn);
$fail = 0;
function ok($c, $m) { global $fail; if (!$c) { $fail++; echo "FAIL $m\n"; } }

$tag = 'ledger_' . bin2hex(random_bytes(3));
$users = [];
for ($i = 0; $i < 6; $i++) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute(["L$i", "$tag$i@t.local", 'x']);
    $users[] = (int) $conn->lastInsertId();
}
mt_srand(11);
for ($round = 0; $round < 40; $round++) {
    $owner = $users[0];
    $gid = shared_create_group($conn, $owner, "G$round", 'users');
    $n = mt_rand(2, 6);
    $mem = array_slice($users, 0, $n);
    foreach (array_slice($mem, 1) as $u) { shared_add_member($conn, $gid, $u); }
    $group = shared_group_for($conn, $gid, $owner);
    $ledger = []; // [cur][uid] => net
    $exp = [];    // id => [cur, paid_by, amount, shares]
    $sets = [];   // settlements [cur, from, to, amount]
    $add = function ($cur, $uid, $v) use (&$ledger) { $ledger[$cur][$uid] = ($ledger[$cur][$uid] ?? 0) + $v; };
    $apply = function ($e, $sign) use ($add) { $add($e['cur'], $e['paid'], $sign * $e['amount']); foreach ($e['shares'] as $u => $m) { $add($e['cur'], $u, -$sign * $m); } };
    for ($k = 0; $k < mt_rand(3, 14); $k++) {
        $op = mt_rand(1, 10);
        if ($op <= 6 || !$exp) {            // add expense
            $cur = ['PKR', 'USD', 'EUR'][mt_rand(0, 2)];
            $amount = mt_rand(1, 5000000);
            $parts = array_values(array_filter($mem, fn() => mt_rand(0, 2) > 0)) ?: [$mem[0]];
            $type = ['equal', 'shares'][mt_rand(0, 1)];
            $inputs = []; foreach ($parts as $u) { $inputs[$u] = (string) mt_rand(1, 5); }
            $r = shared_compute_split($amount, $type, $parts, $inputs);
            $paid = $mem[mt_rand(0, $n - 1)];
            $id = shared_expense_save($conn, $group, $owner, ['title' => 'x', 'amount_minor' => $amount, 'currency' => $cur, 'paid_by' => $paid, 'split_type' => $type, 'expense_date' => date('Y-m-d'), 'note' => null, 'shares' => $r['shares'], 'weights' => $r['weights']], null, null);
            $exp[$id] = ['cur' => $cur, 'paid' => $paid, 'amount' => $amount, 'shares' => $r['shares']];
            $apply($exp[$id], 1);
        } elseif ($op <= 8) {               // delete expense
            $id = array_rand($exp);
            shared_expense_delete($conn, shared_expense_get($conn, $id, $gid));
            $apply($exp[$id], -1); unset($exp[$id]);
        } elseif ($op == 9) {               // edit expense (new amount, equal split among all)
            $id = array_rand($exp); $old = $exp[$id];
            $amount = mt_rand(1, 5000000);
            $r = shared_compute_split($amount, 'equal', $mem, []);
            shared_expense_save($conn, $group, $owner, ['title' => 'y', 'amount_minor' => $amount, 'currency' => $old['cur'], 'paid_by' => $old['paid'], 'split_type' => 'equal', 'expense_date' => date('Y-m-d'), 'note' => null, 'shares' => $r['shares'], 'weights' => $r['weights']], null, shared_expense_get($conn, $id, $gid));
            $apply($old, -1); $exp[$id] = ['cur' => $old['cur'], 'paid' => $old['paid'], 'amount' => $amount, 'shares' => $r['shares']]; $apply($exp[$id], 1);
        } else {                            // settle-up payment
            $f = $mem[mt_rand(0, $n - 1)]; $t = $mem[mt_rand(0, $n - 1)]; if ($f === $t) { continue; }
            $cur = ['PKR', 'USD', 'EUR'][mt_rand(0, 2)]; $amt = mt_rand(1, 100000);
            $conn->prepare('INSERT INTO shared_settlements (group_id,from_user,to_user,amount_minor,currency,settled_on,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$gid, $f, $t, $amt, $cur, date('Y-m-d'), $owner]);
            $add($cur, $f, $amt); $add($cur, $t, -$amt); $sets[] = [$cur, $f, $t, $amt];
        }
    }
    $nets = shared_group_nets($conn, [$gid])[$gid] ?? [];
    foreach ($ledger as $cur => $by) {
        foreach ($by as $u => $v) { ok(($nets[$cur][$u] ?? 0) === $v, "round $round $cur user $u db=" . ($nets[$cur][$u] ?? 0) . " ledger=$v"); }
    }
    foreach ($nets as $cur => $by) {
        ok(array_sum($by) === 0, "round $round $cur nets sum to zero");
        // person-to-person payments must settle everybody, and never be zero or between the same person
        $chk = $by; foreach (shared_group_pairs($conn, [$gid])[$gid][$cur] ?? [] as $p) { ok($p['amount'] > 0 && $p['from'] !== $p['to'], 'pair sane'); $chk[$p['from']] += $p['amount']; $chk[$p['to']] -= $p['amount']; }
        ok(array_sum(array_map('abs', $chk)) === 0, "round $round $cur pair payments settle everyone");
    }
    // every expense's splits add up to its total
    $bad = $conn->prepare('SELECT COUNT(*) FROM shared_expenses e WHERE e.group_id = ? AND e.amount_minor <> (SELECT COALESCE(SUM(share_minor),0) FROM shared_splits s WHERE s.expense_id = e.id)');
    $bad->execute([$gid]); ok((int) $bad->fetchColumn() === 0, "round $round splits sum to totals");
    // independent pairwise ledger from the same inputs
    $pl = [];
    foreach ($exp as $e) { foreach ($e['shares'] as $u => $m) { if ($u !== $e['paid']) { $pl[$e['cur']][$u][$e['paid']] = ($pl[$e['cur']][$u][$e['paid']] ?? 0) + $m; } } }
    foreach ($sets as $st) { $pl[$st[0]][$st[1]][$st[2]] = ($pl[$st[0]][$st[1]][$st[2]] ?? 0) - $st[3]; }
    foreach (['PKR', 'USD', 'EUR'] as $cur) {
        $want = shared_pair_debts($pl[$cur] ?? []);
        $got = shared_group_pairs($conn, [$gid])[$gid][$cur] ?? [];
        ok($want == $got, "round $round $cur pairwise matches independent ledger");
    }
    // the user summary matches the group nets
    $sum = shared_user_summary($conn, [$gid], $mem[0]);
    foreach (['PKR', 'USD', 'EUR'] as $cur) {
        $net = $nets[$cur][$mem[0]] ?? 0;
        ok((($sum['owed'][$cur] ?? 0) - ($sum['owe'][$cur] ?? 0)) === $net, "round $round $cur summary net");
    }
    shared_delete_group($conn, $gid);
}
// account deletion writes the debts off instead of leaving unsettleable ghosts
$gid = shared_create_group($conn, $users[0], 'W', 'users'); shared_add_member($conn, $gid, $users[1]); shared_add_member($conn, $gid, $users[2]);
$group = shared_group_for($conn, $gid, $users[0]);
$r = shared_compute_split(9000, 'equal', [$users[0], $users[1], $users[2]], []);
shared_expense_save($conn, $group, $users[0], ['title' => 'w', 'amount_minor' => 9000, 'currency' => 'PKR', 'paid_by' => $users[1], 'split_type' => 'equal', 'expense_date' => date('Y-m-d'), 'note' => null, 'shares' => $r['shares'], 'weights' => $r['weights']], null, null);
shared_on_user_deleted($conn, $users[1]);
$conn->prepare('DELETE FROM users WHERE id = ?')->execute([$users[1]]);
$nets = shared_group_nets($conn, [$gid])[$gid]['PKR'] ?? [];
ok(($nets[$users[1]] ?? 0) === 0 && ($nets[$users[0]] ?? 0) === 0 && ($nets[$users[2]] ?? 0) === 0, 'deleted account debts written off ' . json_encode($nets));
ok(!shared_has_open_balance($conn, $gid, $users[0]), 'no open balance after write-off');
shared_delete_group($conn, $gid);
$conn->exec('DELETE FROM users WHERE email LIKE ' . $conn->quote($tag . '%'));
echo $fail ? "FAILED $fail\n" : "ledger tests OK\n";
exit($fail ? 1 : 0);
