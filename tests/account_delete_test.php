<?php
/**
 * Account deletion against a TEST database: every module's data of the user is cleaned,
 * other people's balances are written off, nobody else's data is touched.
 * Usage: php tests/account_delete_test.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
$fail = 0;
function ok($c, $m) { global $fail; if (!$c) { $fail++; echo "FAIL $m\n"; } }
$n = fn(string $sql, array $p = []) => (function () use ($conn, $sql, $p) { $s = $conn->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); })();

shared_ensure_all_schemas($conn);
$tag = 'del_' . bin2hex(random_bytes(3));
$u = [];
foreach (['Gone', 'Stay'] as $i => $name) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute([$name, "$tag$i@t.local", 'x']);
    $u[$name] = (int) $conn->lastInsertId();
}
$err = null;
$gOwned = shared_create_group($conn, $u['Gone'], 'GoneOwned', 'home', $err);   // Stay joins -> ownership moves
$gSolo = shared_create_group($conn, $u['Gone'], 'GoneSolo', 'home', $err);     // nobody else -> deleted
shared_add_member($conn, $gOwned, $u['Stay']);
$conn->prepare("INSERT INTO shared_expenses (group_id,title,amount_minor,currency,paid_by,created_by,split_type,expense_date) VALUES (?,?,?,?,?,?,'equal',CURDATE())")->execute([$gOwned, 'Dinner', 20000, 'PKR', $u['Gone'], $u['Gone']]);
$e = (int) $conn->lastInsertId();
foreach ([$u['Gone'], $u['Stay']] as $id) { $conn->prepare('INSERT INTO shared_splits (expense_id,user_id,share_minor) VALUES (?,?,10000)')->execute([$e, $id]); }
$conn->prepare('INSERT INTO comments_items (group_id,expense_id,user_id,body) VALUES (?,?,?,?)')->execute([$gOwned, $e, $u['Gone'], 'bye']);
$conn->prepare('INSERT INTO comments_items (group_id,expense_id,user_id,body) VALUES (?,?,?,?)')->execute([$gOwned, $e, $u['Stay'], 'stay note']);
$conn->prepare('INSERT INTO activity_notifications (user_id,event_id) VALUES (?,?)')->execute([$u['Gone'], 0]) ;
$conn->prepare('INSERT INTO income (user_id,title,amount,currency,amount_pkr,transaction_date) VALUES (?,?,?,?,?,CURDATE())')->execute([$u['Gone'], 'x', 5, 'PKR', 5]);

$conn->beginTransaction();
shared_on_user_deleted($conn, $u['Gone']);
$conn->prepare('DELETE FROM users WHERE id=?')->execute([$u['Gone']]);
$conn->commit();

ok($n('SELECT COUNT(*) FROM shared_members WHERE user_id=?', [$u['Gone']]) === 0, 'left every group');
ok($n('SELECT owner_id FROM shared_groups WHERE id=?', [$gOwned]) === $u['Stay'], 'ownership handed to Stay');
ok($n('SELECT COUNT(*) FROM shared_groups WHERE id=?', [$gSolo]) === 0, 'solo group deleted');
ok($n('SELECT COUNT(*) FROM comments_items WHERE user_id=?', [$u['Gone']]) === 0, 'own comments deleted');
ok($n('SELECT COUNT(*) FROM comments_items WHERE user_id=?', [$u['Stay']]) === 1, "other people's comments kept");
ok($n('SELECT COUNT(*) FROM activity_notifications WHERE user_id=?', [$u['Gone']]) === 0, 'notifications deleted');
$pairs = shared_group_pairs($conn, [$gOwned]);
ok(empty($pairs), "Stay's debt to the deleted account is written off");
ok($n('SELECT COUNT(*) FROM shared_expenses WHERE group_id=?', [$gOwned]) === 1, 'shared expense history kept');

shared_delete_group($conn, $gOwned);
$conn->prepare('DELETE FROM income WHERE user_id=?')->execute([$u['Gone']]);
$conn->prepare('DELETE FROM users WHERE id=?')->execute([$u['Stay']]);
echo $fail ? "$fail FAILED\n" : "account_delete_test: all passed\n";
exit($fail ? 1 : 0);
