<?php
/**
 * Roles and permissions against a TEST database.
 * Usage: php tests/admin_roles_test.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/logging.php';
require __DIR__ . '/../modules/admin/lib.php';
admin_ensure_schema($conn);
$fail = 0;
function ok($c, $m) { global $fail; if (!$c) { $fail++; echo "FAIL $m\n"; } }

ok(admin_can($conn, 'super', 'set') && admin_can($conn, 'super', 'SA'), 'super can do everything');
ok(!admin_can($conn, 'user', 'view'), 'plain user can do nothing');
ok(admin_can($conn, 'admin', 'view') && !admin_can($conn, 'admin', 'del'), 'admin defaults: view yes, delete no');
ok(!admin_can($conn, 'admin', 'SA') && !admin_can($conn, 'support', 'SA'), 'SA is super only');
ok(admin_can($conn, 'support', 'support') && !admin_can($conn, 'support', 'bc'), 'support defaults');
ok(!admin_can($conn, 'admin', 'nonsense'), 'unknown permission is denied');

ok(admin_set_perm($conn, 'admin', 'del', true), 'set perm');
ok(admin_can($conn, 'admin', 'del'), 'admin may now delete');
ok(!admin_set_perm($conn, 'super', 'del', false) && !admin_set_perm($conn, 'admin', 'bogus', true), 'cannot edit super or unknown perms');
admin_set_perm($conn, 'admin', 'del', false);
ok(!admin_can($conn, 'admin', 'del'), 'switched back');

$tag = 'role_' . bin2hex(random_bytes(3));
$conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute(['R', "$tag@t.local", 'x']);
$id = (int) $conn->lastInsertId();
$row = fn() => $conn->query("SELECT role, is_admin FROM users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
ok($row()['role'] === 'user', 'new users are plain users');
admin_set_role($conn, $id, 'support');
ok($row() === ['role' => 'support', 'is_admin' => 1], 'support is staff (is_admin=1)');
admin_set_role($conn, $id, 'user');
ok($row() === ['role' => 'user', 'is_admin' => 0], 'back to user (is_admin=0)');
ok(!admin_set_role($conn, $id, 'root'), 'unknown role refused');
$before = admin_super_count($conn);
admin_set_role($conn, $id, 'super');
ok(admin_super_count($conn) === $before + 1, 'super count');
admin_audit($conn, $id, 'test_x', 'audit works');
ok((int) $conn->query("SELECT COUNT(*) FROM user_logs WHERE user_id = $id AND action = 'admin_test_x'")->fetchColumn() === 1, 'audit line written');
$conn->prepare('DELETE FROM user_logs WHERE user_id=?')->execute([$id]);
$conn->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
echo $fail ? "$fail FAILED\n" : "admin_roles_test: all passed\n";
exit($fail ? 1 : 0);
