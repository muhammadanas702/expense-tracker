<?php
// Part 131: admin controls for alerts (validation, switches, hours). Run: php tests/push_admin_test.php
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/profile/lib.php';
require __DIR__ . '/../modules/push/nudges.php';
array_map('unlink', glob(__DIR__ . '/../storage/schema/*') ?: []);
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
$conn->exec("DELETE FROM app_settings WHERE k LIKE 'push\\_%'"); $GLOBALS['__push_cfg'] = null;
ok(push_cfg_on($conn, 'push_web') && push_cfg($conn, 'push_debt_days') === '2' && push_idle_days($conn) === [3, 7, 14, 30], 'defaults: everything on, 2 days, 3/7/14/30');
$good = ['push_web' => '1', 'push_debt_days' => '5', 'push_idle_days' => '10, 2,2', 'push_from' => '8', 'push_to' => '23'];
[$v, $e] = push_cfg_validate($good);
ok($e === null && $v['push_app'] === '0' && $v['push_idle_days'] === '2,10' && $v['push_debt_days'] === '5', 'valid form is cleaned (sorted, no repeats, unticked = off)');
foreach ([['push_debt_days', '0'], ['push_debt_days', '31'], ['push_idle_days', 'a,b'], ['push_idle_days', ''], ['push_idle_days', '1,2,3,4,5'], ['push_from', '9'], ['push_to', '0'], ['push_from', '24']] as [$k, $val]) {
    $in = $good; $in[$k] = $val; if ($k === 'push_from' && $val === '9') { $in['push_to'] = '9'; }
    ok(push_cfg_validate($in)[1] !== null, "rejects $k = $val");
}
ok(count(push_cfg_save($conn, $v)) >= 4, 'saving reports the changes');
ok(push_cfg($conn, 'push_debt_days') === '5' && !push_cfg_on($conn, 'push_app') && push_idle_days($conn) === [2, 10], 'the saved values are used');
ok(push_cfg_save($conn, $v) === [], 'saving the same again changes nothing');
$h = ((int) gmdate('G') + PUSH_PK_OFFSET) % 24;
push_cfg_save($conn, ['push_from' => (string) $h, 'push_to' => (string) ($h + 1 > 24 ? 1 : $h + 1)]); ok(push_in_window($conn), 'inside the hours');
push_cfg_save($conn, ['push_from' => (string) (($h + 3) % 24), 'push_to' => (string) ((($h + 5) % 24) ?: 24)]); ok(!push_in_window($conn), 'outside the hours');
push_cfg_save($conn, ['push_from' => '22', 'push_to' => '6']); $x = ((int) gmdate('G') + 5) % 24; ok(push_in_window($conn) === ($x >= 22 || $x < 6), 'hours may cross midnight');

// switches really stop things
$conn->exec("DELETE FROM users WHERE email='pa1@t.local'"); $conn->prepare("INSERT INTO users (name,email,password) VALUES ('PA','pa1@t.local','x')")->execute(); $u = (int) $conn->lastInsertId();
push_ensure_schema($conn); $conn->exec("DELETE FROM app_devices"); $conn->exec("DELETE FROM app_inbox");
app_device_token_new($conn, $u, 'en');
push_cfg_save($conn, ['push_app' => '1']); ok(push_notify($conn, [$u], 'T', 'b') === 1, 'app channel on: queued');
push_cfg_save($conn, ['push_app' => '0']); ok(push_notify($conn, [$u], 'T', 'b') === 0 && push_notify_i18n($conn, [$u], fn() => ['T', 'b']) === 0, 'app channel off: nothing queued');
push_cfg_save($conn, ['push_app' => '1', 'push_idle' => '0', 'push_debt_days' => '2', 'push_from' => '0', 'push_to' => '24']);
$conn->exec("INSERT INTO push_nudges (user_id,last_seen) VALUES ($u, NOW() - INTERVAL 5 DAY) ON DUPLICATE KEY UPDATE last_seen = NOW() - INTERVAL 5 DAY, idle_stage = 0, idle_at = NULL");
[, $b] = push_tick($conn, true); ok($b === 0, 'nudges off: no we-miss-you');
push_cfg_save($conn, ['push_idle' => '1', 'push_idle_days' => '9']); [, $b] = push_tick($conn, true); ok($b === 0, 'custom schedule: not before 9 days');
push_cfg_save($conn, ['push_idle_days' => '4']); [, $b] = push_tick($conn, true); ok($b === 1, 'custom schedule: after 4 days');
$conn->exec("DELETE FROM app_settings WHERE k LIKE 'push\\_%'"); $GLOBALS['__push_cfg'] = null;
push_on_user_deleted($conn, $u); $conn->exec("DELETE FROM users WHERE email='pa1@t.local'");
echo $f ? "push_admin_test: $f FAILED\n" : "push_admin_test: ALL PASSED\n"; exit($f ? 1 : 0);
