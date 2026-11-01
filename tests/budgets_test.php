<?php
/** Budgets: validation, month status, alert de-duplication, clean-up. Usage: php tests/budgets_test.php (TEST database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/shared/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }

$cats = ['Food', 'Bills'];
[$v, $e] = budgets_validate(['category' => 'Nope', 'amount' => '100', 'currency' => 'PKR'], $cats);
ok($v === null && $e, 'unknown category refused');
foreach (['0', '-5', 'abc', '', '1e9', '99999999999999'] as $bad) {
    [$v] = budgets_validate(['category' => 'Food', 'amount' => $bad, 'currency' => 'PKR'], $cats);
    ok($v === null, "amount '$bad' refused");
}
[$v] = budgets_validate(['category' => 'Food', 'amount' => '1,500.50', 'currency' => 'PKR'], $cats);
ok($v && $v['amount'] === 1500.5, 'comma amount accepted');
[$v] = budgets_validate(['category' => 'Food', 'amount' => '10', 'currency' => 'ZZZ'], $cats);
ok($v === null, 'unknown currency refused');

$conn->exec("DELETE FROM users WHERE email='bud1@bud.local'");
$conn->exec("INSERT INTO users(name,email,password) VALUES ('Bud One','bud1@bud.local','x')");
$uid = (int) $conn->query("SELECT id FROM users WHERE email='bud1@bud.local'")->fetchColumn();
personal_ensure_schema($conn); budgets_ensure_schema($conn);
$conn->prepare('DELETE FROM budgets WHERE user_id=?')->execute([$uid]);
$conn->prepare('DELETE FROM budget_alerts WHERE user_id=?')->execute([$uid]);

ok(budgets_save($conn, $uid, ['category' => 'Food', 'amount' => 1000.0, 'currency' => 'PKR']), 'save works');
budgets_save($conn, $uid, ['category' => 'Food', 'amount' => 2000.0, 'currency' => 'PKR']);
ok((int) $conn->query("SELECT COUNT(*) FROM budgets WHERE user_id=$uid")->fetchColumn() === 1, 'same category updates, not duplicates');
$ins = $conn->prepare("INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date) VALUES (?,?,?,?,?,?,NOW())");
$ins->execute([$uid, 'a', 1000, 'PKR', 1000, 'Food']);
$ins->execute([$uid, 'old', 5000, 'PKR', 5000, 'Food']);
$conn->prepare("UPDATE expenses SET transaction_date = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE user_id=? AND title='old'")->execute([$uid]);
$ins->execute([$uid, 'other', 900, 'PKR', 900, 'Bills']);
$st = budgets_status($conn, $uid);
ok(count($st) === 1 && $st[0]['spent'] === 1000.0 && $st[0]['pct'] === 50.0 && $st[0]['level'] === 'ok', 'only this month and this category counted (50%)');

$notes = fn() => (int) $conn->query("SELECT COUNT(*) FROM activity_notifications n JOIN activity_events e ON e.id=n.event_id WHERE n.user_id=$uid AND e.kind LIKE 'budget\\_%'")->fetchColumn();
ok(budgets_check_alerts($conn, $uid, 'Food') === 0 && $notes() === 0, 'no alert at 50%');
$ins->execute([$uid, 'b', 600, 'PKR', 600, 'Food']); // 1600 / 2000 = 80%
ok(budgets_check_alerts($conn, $uid, 'Food') === 80 && $notes() === 1, '80% alert sent');
ok(budgets_check_alerts($conn, $uid, 'Food') === 0 && $notes() === 1, '80% alert not repeated');
$ins->execute([$uid, 'c', 500, 'PKR', 500, 'Food']); // 2100 = 105%
$stt = budgets_status($conn, $uid);
ok($stt[0]['level'] === 'over' && $stt[0]['left'] < 0, 'over budget detected');
ok(budgets_check_alerts($conn, $uid, 'Food') === 100 && $notes() === 2, '100% alert sent once');
ok(budgets_check_alerts($conn, $uid, 'Food') === 0 && $notes() === 2, '100% alert not repeated');
$conn->prepare('DELETE FROM budget_alerts WHERE user_id=?')->execute([$uid]);
$conn->exec("DELETE n FROM activity_notifications n WHERE n.user_id=$uid");
$ins->execute([$uid, 'd', 1, 'PKR', 1, 'Food']);
ok(budgets_check_alerts($conn, $uid, 'Food') === 100 && $notes() === 1, 'jumping straight past 100% gives one message, not two');

$conn->prepare('UPDATE user_settings SET notifications=0 WHERE user_id=?')->execute([$uid]);
$conn->exec("INSERT IGNORE INTO user_settings (user_id, notifications) VALUES ($uid, 0)");
$conn->prepare('DELETE FROM budget_alerts WHERE user_id=?')->execute([$uid]);
$conn->exec("DELETE FROM activity_notifications WHERE user_id=$uid");
budgets_check_alerts($conn, $uid, 'Food');
ok($notes() === 0, 'notifications switched off: nothing delivered');

budgets_on_user_deleted($conn, $uid);
ok((int) $conn->query("SELECT COUNT(*) FROM budgets WHERE user_id=$uid")->fetchColumn() === 0, 'account deletion removes budgets');
$conn->exec("DELETE FROM expenses WHERE user_id=$uid");
$conn->exec("DELETE FROM user_settings WHERE user_id=$uid");
$conn->exec("DELETE FROM users WHERE id=$uid");
echo $fails ? "budgets tests: $fails FAILED\n" : "budgets tests: ALL PASSED\n";
exit($fails ? 1 : 0);
