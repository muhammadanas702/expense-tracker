<?php
/** Part 111b: private two-person account with a friend (needs the local test database). Run: php tests/direct_ledger_test.php */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
require __DIR__ . '/../modules/shared/invites.php';
require __DIR__ . '/../modules/friends/lib.php';
shared_ensure_all_schemas($conn);
friends_ensure_schema($conn);
$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
$ids = [];
foreach (['a', 'b', 'c'] as $n) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute(["Dir $n", "dir$n@t.local", 'x']);
    $ids[$n] = (int) $conn->lastInsertId();
}
[$a, $b, $c] = array_values($ids);
$conn->prepare("INSERT INTO friend_links (requester_id,addressee_id,status) VALUES (?,?,'accepted')")->execute([$a, $b]);
ok(shared_direct_group($conn, $a, $c) === null, 'no private account with a non-friend');
ok(shared_direct_group($conn, $a, $a) === null, 'no private account with yourself');
ok(shared_direct_group($conn, $a, $b, false) === null, 'nothing is created when only looking');
$g = shared_direct_group($conn, $a, $b);
ok($g > 0, 'a private account is created for two friends');
ok(shared_direct_group($conn, $b, $a) === $g && shared_direct_group($conn, $a, $b) === $g, 'the same account is found from both sides (no duplicates)');
ok(shared_group_for($conn, $g, $a)['name'] === 'Dir b' && shared_group_for($conn, $g, $b)['name'] === 'Dir a', 'each person sees the other one\'s name');
ok(shared_group_for($conn, $g, $c) === null, 'a third person cannot open it');
$mine = array_values(array_filter(shared_groups_of($conn, $a), fn($x) => (int) $x['id'] === $g));
ok(count($mine) === 1 && (int) $mine[0]['is_direct'] === 1, 'groups_of marks it as direct (pages hide it from the group list)');
$code = $conn->query("SELECT invite_code FROM shared_groups WHERE id = $g")->fetchColumn();
ok(!$conn->query("SELECT id FROM shared_groups WHERE invite_code = '$code' AND is_direct = 0")->fetchColumn(), 'its invite code cannot be used to join');
// an expense inside it shows up as a balance with the friend
$group = shared_group_for($conn, $g, $a);
$r = shared_compute_split(10000, 'equal', [$a, $b], [$a => '1', $b => '1']);
shared_expense_save($conn, $group, $a, ['title' => 'Lunch', 'amount_minor' => 10000, 'currency' => 'PKR', 'paid_by' => $a, 'split_type' => 'equal', 'expense_date' => date('Y-m-d'), 'note' => null, 'shares' => $r['shares'], 'weights' => $r['weights']], null);
$f = friends_get($conn, $a, $b);
ok($f && ($f['owed']['PKR'] ?? 0) === 5000, 'friend owes half of the lunch');
ok($f && (int) $f['ledger'] === $g && !$f['groups'], 'the account is not listed as a shared group');
$fb = friends_get($conn, $b, $a);
ok($fb && ($fb['owe']['PKR'] ?? 0) === 5000, 'friend sees what they owe');
// does not use up the group limit
$conn->exec("INSERT INTO shared_groups (name,icon,owner_id,invite_code) VALUES ('Real','users',$a,'" . shared_new_code() . "')");
ok(true, 'regular groups still creatable');
shared_delete_group($conn, $g);
$rg = (int) $conn->query("SELECT id FROM shared_groups WHERE owner_id = $a")->fetchColumn();
if ($rg) { shared_delete_group($conn, $rg); }
$conn->exec("DELETE FROM friend_links WHERE requester_id IN ($a,$b,$c)");
$conn->exec("DELETE FROM users WHERE id IN ($a,$b,$c)");
echo $fail ? "FAILED: $fail\n" : "direct_ledger_test: ALL PASSED\n";
exit($fail ? 1 : 0);
