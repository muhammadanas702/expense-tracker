<?php
/**
 * Friends check against a TEST database: friends = people sharing a group, balances person by person.
 * Usage: php tests/friends_test.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
require __DIR__ . '/../modules/friends/lib.php';
shared_ensure_schema($conn);
$fail = 0;
function ok($c, $m) { global $fail; if (!$c) { $fail++; echo "FAIL $m\n"; } }

$tag = 'fr_' . bin2hex(random_bytes(3));
$u = [];
foreach (['Me', 'Ali', 'Munna', 'Stranger'] as $i => $n) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute([$n, "$tag$i@t.local", 'x']);
    $u[$n] = (int) $conn->lastInsertId();
}
$err = null;
$g1 = shared_create_group($conn, $u['Me'], 'Trip', 'mtn', $err);
$g2 = shared_create_group($conn, $u['Me'], 'Flat', 'home', $err);
$gx = shared_create_group($conn, $u['Stranger'], 'Other', 'users', $err);
foreach ([[$g1, 'Ali'], [$g1, 'Munna'], [$g2, 'Ali']] as [$g, $n]) { shared_add_member($conn, $g, $u[$n]); }
$ins = fn($g, $payer, $cur, $amt, $with) => (function () use ($conn, $g, $payer, $cur, $amt, $with) {
    $conn->prepare('INSERT INTO shared_expenses (group_id,title,amount_minor,currency,paid_by,expense_date,created_by,split_type) VALUES (?,?,?,?,?,CURDATE(),?,\'equal\')')->execute([$g, 'x', $amt, $cur, $payer, $payer]);
    $id = (int) $conn->lastInsertId();
    $each = intdiv($amt, count($with));
    foreach ($with as $w) { $conn->prepare('INSERT INTO shared_splits (expense_id,user_id,share_minor) VALUES (?,?,?)')->execute([$id, $w, $each]); }
})();

// Me pays 1000 in Trip split with Ali + Munna (each owes Me 333.33 -> 33333 minor of 100000/3)
$ins($g1, $u['Me'], 'PKR', 90000, [$u['Me'], $u['Ali'], $u['Munna']]);   // 300 each
// Ali pays 200 in Flat split with Me -> Me owes Ali 100
$ins($g2, $u['Ali'], 'PKR', 20000, [$u['Me'], $u['Ali']]);

$f = friends_list($conn, $u['Me']);
ok(count($f) === 2, 'two friends (Ali, Munna), stranger excluded');
ok(!isset($f[$u['Stranger']]), 'stranger not a friend');
ok(isset($f[$u['Ali']]) && count($f[$u['Ali']]['groups']) === 2, 'Ali shares 2 groups');
ok(($f[$u['Ali']]['owed']['PKR'] ?? 0) === 30000 && ($f[$u['Ali']]['owe']['PKR'] ?? 0) === 10000, 'Ali: owes me 300, I owe 100 (pairwise, per group)');
ok(friends_net($f[$u['Ali']]) === ['PKR' => 20000], 'Ali net +200');
ok(friends_net($f[$u['Munna']]) === ['PKR' => 30000], 'Munna net +300');
ok(friends_get($conn, $u['Me'], $u['Stranger']) === null, 'cannot open a non-friend');
ok(friends_get($conn, $u['Stranger'], $u['Me']) === null, 'symmetry: stranger has no friends from Me');

// settle: Munna pays 300
$conn->prepare('INSERT INTO shared_settlements (group_id,from_user,to_user,amount_minor,currency,settled_on,created_by) VALUES (?,?,?,?,?,CURDATE(),?)')->execute([$g1, $u['Munna'], $u['Me'], 30000, 'PKR', $u['Munna']]);
$f = friends_list($conn, $u['Me']);
ok(friends_net($f[$u['Munna']]) === [], 'Munna settled');
ok(array_key_first($f) === $u['Ali'], 'open balances sorted first');
ok(count(friends_settlements($conn, $u['Me'], $u['Munna'], array_keys($f[$u['Munna']]['groups']))) === 1, 'payment history');

// leaving the group removes the friend
shared_remove_member($conn, $g1, $u['Munna']);
ok(!isset(friends_list($conn, $u['Me'])[$u['Munna']]), 'ex-member is not a friend');

foreach ([$g1, $g2, $gx] as $g) { shared_delete_group($conn, $g); }
foreach ($u as $id) { $conn->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
echo $fail ? "$fail FAILED\n" : "friends_test: all passed\n";
exit($fail ? 1 : 0);
