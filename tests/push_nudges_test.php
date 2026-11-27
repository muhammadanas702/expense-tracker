<?php
// Part 129: phone nudges (2-day debt reminder, "we miss you" stages, opt-out, language). Run: php tests/push_nudges_test.php
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/profile/lib.php';
require __DIR__ . '/../modules/push/nudges.php';
array_map('unlink', glob(__DIR__ . '/../storage/schema/*') ?: []);
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }

$conn->exec("DELETE FROM users WHERE email IN ('pn1@t.local','pn2@t.local','pn3@t.local')");
$mk = $conn->prepare("INSERT INTO users (name,email,password) VALUES (?,?,'x')");
foreach ([['Creditor', 'pn1@t.local'], ['Debtor', 'pn2@t.local'], ['Quiet', 'pn3@t.local']] as $i => $r) { $mk->execute($r); $u[$i + 1] = (int) $conn->lastInsertId(); }
profile_ensure_schema($conn); push_ensure_schema($conn);
$conn->exec("DELETE FROM push_subscriptions"); $conn->exec("DELETE FROM push_nudges");
$conn->exec("INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES ({$u[3]}, 20, 0, 1) ON DUPLICATE KEY UPDATE notifications = 0");
foreach ($u as $id) {
    [, $uaPub] = push_new_keypair();
    push_subscribe($conn, $id, 'https://push.example.com/' . $id, push_b64u($uaPub), push_b64u(random_bytes(16)), $id === $u[2] ? 'ur' : 'en');
}
$conn->exec("INSERT INTO shared_groups (name, icon, owner_id, invite_code) VALUES ('PNTrip','users',{$u[1]},MD5(RAND()))"); $g = (int) $conn->lastInsertId();
foreach ([1, 2] as $k) { $conn->exec("INSERT INTO shared_members (group_id,user_id,role) VALUES ($g,{$u[$k]},'" . ($k === 1 ? 'owner' : 'member') . "')"); }
$conn->exec("INSERT INTO shared_expenses (group_id,title,amount_minor,currency,paid_by,created_by,expense_date,split_type) VALUES ($g,'Hotel',200000,'PKR',{$u[1]},{$u[1]},CURDATE(),'equal')"); $x = (int) $conn->lastInsertId();
foreach ([1, 2] as $k) { $conn->exec("INSERT INTO shared_splits (expense_id,user_id,share_minor) VALUES ($x,{$u[$k]},100000)"); }
$sink = sys_get_temp_dir() . '/push_nudge_sink_' . getmypid(); @unlink($sink); putenv("EXPENSEFLOW_PUSH_SINK=$sink");
$lines = fn() => is_file($sink) ? count(file($sink)) : 0;
$conn->exec("UPDATE push_nudges SET last_seen = NOW()");

// debt reminder
$d = push_debt_summary($conn, $u[2]);
ok($d && $d['amount'] === 100000 && $d['name'] === 'Creditor' && $d['count'] === 1, 'the debtor owes the creditor 1,000.00');
ok(push_debt_summary($conn, $u[1]) === null, 'the creditor owes nobody');
[$a, $b] = push_tick($conn, true);
ok($a === 1 && $lines() === 1, 'only the debtor gets a debt reminder (the creditor and the opted-out person do not)');
[$a] = push_tick($conn, true);
ok($a === 0 && $lines() === 1, 'no second reminder within 2 days');
$conn->exec("UPDATE push_nudges SET debt_at = NOW() - INTERVAL 3 DAY, debt_check_at = NOW() - INTERVAL 7 HOUR WHERE user_id = {$u[2]}");
[$a] = push_tick($conn, true);
ok($a === 1 && $lines() === 2, 'after 2 days the reminder comes again');
ok(ef_with_lang('ur', fn() => t('A small reminder')) === 'ایک چھوٹی سی یاد دہانی' && t('A small reminder') === 'A small reminder', 'wording follows the receiver language');
$w = ef_with_lang('en', fn() => push_debt_words($d)); ok(str_contains($w[1], 'Creditor') && str_contains($w[1], '1,000.00'), 'the message names the person and the amount');
$conn->exec("UPDATE push_subscriptions SET lang='en'");

// settle up: no reminder any more
$conn->exec("INSERT INTO shared_settlements (group_id, from_user, to_user, amount_minor, currency, created_by, settled_on) VALUES ($g,{$u[2]},{$u[1]},100000,'PKR',{$u[2]},CURDATE())");
$conn->exec("UPDATE push_nudges SET debt_at = NOW() - INTERVAL 3 DAY, debt_check_at = NOW() - INTERVAL 7 HOUR");
$before = $lines(); [$a] = push_tick($conn, true);
ok($a === 0 && $lines() === $before, 'nothing is sent once the debt is settled');

// we miss you
$conn->exec("UPDATE push_nudges SET last_seen = NOW() - INTERVAL 4 DAY, idle_stage = 0, idle_at = NULL WHERE user_id IN ({$u[1]},{$u[2]},{$u[3]})");
$before = $lines(); [, $b] = push_tick($conn, true);
ok($b === 2 && $lines() === $before + 2, 'after 3 days away the two opted-in people get one message (not the opted-out one)');
[, $b] = push_tick($conn, true);
ok($b === 0, 'not again straight away');
$conn->exec("UPDATE push_nudges SET last_seen = NOW() - INTERVAL 8 DAY, idle_at = NOW() - INTERVAL 3 DAY WHERE user_id = {$u[1]}");
[, $b] = push_tick($conn, true);
ok($b === 1, 'the second nudge comes after 7 days');
ok((int) $conn->query("SELECT idle_stage FROM push_nudges WHERE user_id = {$u[1]}")->fetchColumn() === 2, 'stage moves forward');
$_SESSION = []; push_touch_seen($conn, $u[1]);
ok((int) $conn->query("SELECT idle_stage FROM push_nudges WHERE user_id = {$u[1]}")->fetchColumn() === 0, 'opening the app starts the count again');
foreach ([1, 2, 3, 4] as $s) { $w = push_idle_words($s); ok($w[0] !== '' && $w[1] !== '', "idle wording $s exists"); }

// nothing at night unless forced
$conn->exec("UPDATE push_nudges SET last_seen = NOW() - INTERVAL 40 DAY, idle_stage = 0, idle_at = NULL");
if ((int) gmdate('G') < 4 || (int) gmdate('G') >= 17) { $before = $lines(); push_tick($conn); ok($lines() === $before, 'no nudges at night'); }

$conn->exec("DELETE FROM users WHERE email IN ('pn1@t.local','pn2@t.local','pn3@t.local')"); $conn->exec("DELETE FROM shared_groups WHERE id=$g");
@unlink($sink);
echo $f ? "push_nudges_test: $f FAILED\n" : "push_nudges_test: ALL PASSED\n"; exit($f ? 1 : 0);
