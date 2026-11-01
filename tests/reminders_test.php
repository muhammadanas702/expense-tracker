<?php
/** Reminders: validation, month maths, paid, notifications once per due date. Usage: php tests/reminders_test.php (TEST database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/shared/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
$today = new DateTimeImmutable('2026-11-01');

ok(reminders_next_month('2026-01-31') === '2026-02-28' && reminders_next_month('2028-01-31') === '2028-02-29' && reminders_next_month('2026-12-15') === '2027-01-15', 'next month keeps the day, clamps short months, crosses the year');
foreach ([['', '2026-11-05'], ['x', 'nope'], ['x', '2026-02-30'], ['x', '2015-01-01'], [str_repeat('a', 61), '2026-11-05']] as [$t, $d]) {
    [$v] = reminders_validate(['title' => $t, 'due_date' => $d, 'currency' => 'PKR'], $today);
    ok($v === null, "refused title='" . substr($t, 0, 4) . "' date='$d'");
}
foreach (['0', '-5', 'abc'] as $a) {
    [$v] = reminders_validate(['title' => 'x', 'due_date' => '2026-11-05', 'currency' => 'PKR', 'amount' => $a], $today);
    ok($v === null, "amount '$a' refused");
}
[$v] = reminders_validate(['title' => 'x', 'due_date' => '2026-11-05', 'currency' => 'ZZZ'], $today);
ok($v === null, 'bad currency refused');
[$v] = reminders_validate(['title' => ' Net  bill ', 'due_date' => '2026-11-05', 'currency' => 'PKR', 'amount' => '3,500', 'monthly' => '1'], $today);
ok($v && $v['title'] === 'Net bill' && $v['amount'] === 3500.0 && $v['monthly'] === 1, 'valid reminder accepted');
[$v2] = reminders_validate(['title' => 'No amount', 'due_date' => '2026-11-05', 'currency' => 'PKR'], $today);
ok($v2 && $v2['amount'] === null && $v2['monthly'] === 0, 'amount optional');

$conn->exec("DELETE FROM users WHERE email IN ('rem1@r.local','rem2@r.local')");
$conn->exec("INSERT INTO users(name,email,password) VALUES ('R One','rem1@r.local','x'),('R Two','rem2@r.local','x')");
$a = (int) $conn->query("SELECT id FROM users WHERE email='rem1@r.local'")->fetchColumn();
$b = (int) $conn->query("SELECT id FROM users WHERE email='rem2@r.local'")->fetchColumn();
reminders_ensure_schema($conn); personal_ensure_schema($conn);
foreach ([$a, $b] as $x) { reminders_on_user_deleted($conn, $x); }

$mk = fn($title, $date, $monthly = 0, $amt = null) => reminders_create($conn, $a, ['title' => $title, 'amount' => $amt, 'currency' => 'PKR', 'due_date' => $date, 'monthly' => $monthly]);
$late = $mk('Late', '2026-10-29'); $today1 = $mk('Today', '2026-11-01'); $soon = $mk('Soon', '2026-11-03', 1, 3500.0); $later = $mk('Later', '2026-12-30');
$l = reminders_list($conn, $a, $today);
$st = array_column($l, 'state', 'title'); $days = array_column($l, 'days', 'title');
ok($st === ['Late' => 'late', 'Today' => 'today', 'Soon' => 'soon', 'Later' => 'later'], 'states: ' . json_encode($st));
ok($days['Late'] === -3 && $days['Today'] === 0 && $days['Soon'] === 2, 'days: ' . json_encode($days));
ok($l[0]['title'] === 'Late', 'earliest first');
ok(reminders_list($conn, $b, $today) === [], 'other person sees nothing');

$notes = fn() => (int) $conn->query("SELECT COUNT(*) FROM activity_notifications n JOIN activity_events e ON e.id=n.event_id WHERE n.user_id=$a AND e.kind LIKE 'reminder\\_%'")->fetchColumn();
ok(reminders_check_due($conn, $a, $today) === 3 && $notes() === 3, 'three notifications (late, today, soon), none for later');
ok(reminders_check_due($conn, $a, $today) === 0 && $notes() === 3, 'not repeated');
reminders_mark_paid($conn, $a, $soon, false, $today);
ok(reminders_check_due($conn, $a, new DateTimeImmutable('2026-12-02')) >= 1, 'next due date is announced again later');

// paid
ok(reminders_mark_paid($conn, $b, $soon, false, $today) !== null, "other person cannot mark my reminder paid");
$due = fn($id) => $conn->query("SELECT due_date FROM reminders WHERE id=$id")->fetchColumn();
ok($due($soon) === '2026-12-03', 'monthly moved to next month: ' . $due($soon));
reminders_mark_paid($conn, $a, $soon, true, $today);
ok($due($soon) === '2027-01-03', 'paid again moves another month');
ok((int) $conn->query("SELECT COUNT(*) FROM expenses WHERE user_id=$a AND title='Soon' AND category='Bills' AND amount=3500")->fetchColumn() === 1, 'expense recorded only when asked');
reminders_mark_paid($conn, $a, $late, true, $today);
ok((int) $conn->query("SELECT active FROM reminders WHERE id=$late")->fetchColumn() === 0, 'one-time reminder switched off');
ok((int) $conn->query("SELECT COUNT(*) FROM expenses WHERE user_id=$a AND title='Late'")->fetchColumn() === 0, 'no amount: no expense');
ok(reminders_mark_paid($conn, $a, $late, false, $today) !== null, 'cannot pay a finished reminder twice');
// a very old monthly reminder lands in the future
$old = $mk('Old', '2026-06-10', 1);
reminders_mark_paid($conn, $a, $old, false, $today);
ok($due($old) === '2026-11-10', 'old monthly skips past dates: ' . $due($old));

reminders_delete($conn, $b, $later);
ok($due($later) === '2026-12-30', "other person cannot delete");
for ($i = 0; $i < REMINDERS_MAX_PER_USER; $i++) { $mk('x', '2027-01-01'); }
ok($mk('over', '2027-01-01') === 0, 'maximum enforced');
reminders_on_user_deleted($conn, $a);
ok((int) $conn->query("SELECT COUNT(*) FROM reminders WHERE user_id=$a")->fetchColumn() === 0, 'account deletion removes reminders');
$conn->exec("DELETE FROM expenses WHERE user_id=$a");
$conn->exec("DELETE FROM users WHERE id IN ($a,$b)");
echo $fails ? "reminders tests: $fails FAILED\n" : "reminders tests: ALL PASSED\n";
exit($fails ? 1 : 0);
