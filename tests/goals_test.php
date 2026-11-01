<?php
/** Savings goals: validation, money in/out, per-month hint, ownership, clean-up. Usage: php tests/goals_test.php (TEST database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/shared/lib.php';
require_once __DIR__ . '/../includes/modules.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
$today = new DateTimeImmutable('2026-11-01');

ok(ef_nav('p')[0][0] === 'dashboard.php', 'Dashboard stays the first personal menu item');
foreach ([['', '100'], ['x', '0'], ['x', '-1'], ['x', 'abc'], [str_repeat('a', 61), '10']] as [$n, $t]) {
    [$v] = goals_validate(['name' => $n, 'target' => $t, 'currency' => 'PKR'], $today);
    ok($v === null, "refused name='" . substr($n, 0, 5) . "' target='$t'");
}
[$v] = goals_validate(['name' => 'A', 'target' => '10', 'currency' => 'ZZZ'], $today);
ok($v === null, 'bad currency refused');
foreach (['2026-11-01', '2020-01-01', '2026-02-30', 'tomorrow', '2070-01-01'] as $d) {
    [$v] = goals_validate(['name' => 'A', 'target' => '10', 'currency' => 'PKR', 'deadline' => $d], $today);
    ok($v === null, "deadline $d refused");
}
[$v, $e] = goals_validate(['name' => '  New   laptop ', 'target' => '120,000', 'currency' => 'PKR', 'deadline' => '2027-05-01'], $today);
ok($v && $v['name'] === 'New laptop' && $v['target'] === 120000.0 && $v['deadline'] === '2027-05-01', 'valid goal accepted, name tidied');
ok(goals_months_left('2027-05-01', $today) === 6 && goals_months_left('2027-05-02', $today) === 7 && goals_months_left('2026-11-05', $today) === 1, 'months left rounds up, minimum 1');
ok(goals_months_left(null, $today) === null && goals_months_left('2026-10-01', $today) === null, 'no months without a (future) deadline');

$conn->exec("DELETE FROM users WHERE email IN ('goal1@g.local','goal2@g.local')");
$conn->exec("INSERT INTO users(name,email,password) VALUES ('G One','goal1@g.local','x'),('G Two','goal2@g.local','x')");
$a = (int) $conn->query("SELECT id FROM users WHERE email='goal1@g.local'")->fetchColumn();
$b = (int) $conn->query("SELECT id FROM users WHERE email='goal2@g.local'")->fetchColumn();
goals_ensure_schema($conn);
foreach ([$a, $b] as $x) { goals_on_user_deleted($conn, $x); }

$gid = goals_create($conn, $a, $v);
ok($gid > 0, 'goal created');
ok(goals_move($conn, $a, $gid, '20000', 1) === null, 'money added');
ok(goals_move($conn, $a, $gid, '5000.50', -1) === null, 'money taken out');
ok(goals_move($conn, $a, $gid, '99999', -1) !== null, 'cannot take out more than saved');
ok(goals_move($conn, $a, $gid, '0', 1) !== null && goals_move($conn, $a, $gid, '-5', 1) !== null && goals_move($conn, $a, $gid, 'x', 1) !== null, 'bad amounts refused');
ok(goals_move($conn, $b, $gid, '100', 1) !== null, "other person cannot add to my goal");
$l = goals_list($conn, $a, $today)[0];
ok($l['saved'] === 14999.5 && $l['left'] === 105000.5 && !$l['done'] && $l['pct'] === 12.5, 'saved/left/pct correct');
ok($l['months_left'] === 6 && $l['per_month'] === 17500.08, 'per month hint: ' . var_export($l['per_month'], true));
goals_move($conn, $a, $gid, '110000.5', 1);
$l = goals_list($conn, $a, $today)[0];
ok($l['done'] && $l['per_month'] === null && !$l['overdue'], 'goal done, no per-month hint');
ok(goals_list($conn, $b, $today) === [], "other person sees nothing");
goals_delete($conn, $b, $gid);
ok(count(goals_list($conn, $a, $today)) === 1, "other person cannot delete my goal");

for ($i = 0; $i < GOALS_MAX_PER_USER; $i++) { goals_create($conn, $a, $v); }
ok(goals_create($conn, $a, $v) === 0, 'maximum number of goals enforced');
goals_on_user_deleted($conn, $a);
ok((int) $conn->query("SELECT COUNT(*) FROM savings_goals WHERE user_id=$a")->fetchColumn() === 0 && (int) $conn->query("SELECT COUNT(*) FROM goal_entries WHERE user_id=$a")->fetchColumn() === 0, 'account deletion removes goals and entries');
$conn->exec("DELETE FROM users WHERE id IN ($a,$b)");
echo $fails ? "goals tests: $fails FAILED\n" : "goals tests: ALL PASSED\n";
exit($fails ? 1 : 0);
