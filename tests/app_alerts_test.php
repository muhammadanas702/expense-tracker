<?php
// Part 130a: alerts inbox for the Android app (token, queue, poll once, nudges reach app-only people). Run: php tests/app_alerts_test.php
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/profile/lib.php';
require __DIR__ . '/../modules/push/nudges.php';
array_map('unlink', glob(__DIR__ . '/../storage/schema/*') ?: []);
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
$conn->exec("DELETE FROM users WHERE email IN ('aa1@t.local','aa2@t.local')");
$mk = $conn->prepare("INSERT INTO users (name,email,password) VALUES (?,?,'x')");
$mk->execute(['App One', 'aa1@t.local']); $u1 = (int) $conn->lastInsertId();
$mk->execute(['App Two', 'aa2@t.local']); $u2 = (int) $conn->lastInsertId();
profile_ensure_schema($conn); push_ensure_schema($conn);
$conn->exec("DELETE FROM app_devices"); $conn->exec("DELETE FROM app_inbox"); $conn->exec("DELETE FROM push_subscriptions"); $conn->exec("DELETE FROM push_nudges");

$t1 = app_device_token_new($conn, $u1, 'ur');
ok(preg_match('/^[0-9a-f]{64}$/', $t1) === 1, 'a token is 64 hex characters');
ok((string) $conn->query("SELECT COUNT(*) FROM app_devices WHERE token_hash='$t1'")->fetchColumn() === '0', 'the token itself is not stored (only its hash)');
ok(app_device_user($conn, $t1)[0] === $u1 && app_device_user($conn, str_repeat('a', 64))[0] === 0 && app_device_user($conn, 'bad')[0] === 0, 'a token finds its person; wrong ones find nobody');
for ($i = 0; $i < 5; $i++) { app_device_token_new($conn, $u1); }
ok((int) $conn->query("SELECT COUNT(*) FROM app_devices WHERE user_id=$u1")->fetchColumn() === APP_MAX_DEVICES, 'only the newest 3 phones are kept');
$conn->exec("DELETE FROM app_devices"); $t1 = app_device_token_new($conn, $u1, 'ur'); $t2 = app_device_token_new($conn, $u2, 'en');

// a push to both people queues one alert for each, in their own language
$n = push_notify_i18n($conn, [$u1, $u2], fn() => [t('A small reminder'), 'body'], 'dashboard.php');
ok($n === 2, 'both app people got an alert queued');
$a = app_inbox_take($conn, $u1); $b = app_inbox_take($conn, $u2);
ok(count($a) === 1 && $a[0]['title'] === 'ایک چھوٹی سی یاد دہانی' && $a[0]['url'] === 'dashboard.php', 'Urdu phone gets Urdu text and the page to open');
ok(count($b) === 1 && $b[0]['title'] === 'A small reminder', 'English phone gets English text');
ok(app_inbox_take($conn, $u1) === [], 'an alert is handed out only once');
push_notify($conn, [$u1], 'T', 'plain body', 'x.php');
ok(count(app_inbox_take($conn, $u1)) === 1, 'plain pushes are queued too');
$conn->exec("INSERT INTO app_inbox (user_id,title,body,created_at) VALUES ($u1,'old','old', NOW() - INTERVAL 5 DAY)");
ok(app_inbox_take($conn, $u1) === [], 'alerts older than 3 days are not delivered');
ok(push_notify($conn, [999999], 'T', 'x') === 0, 'a person without a phone queues nothing');

// nudges reach a person who has only the app
$conn->exec("INSERT INTO push_nudges (user_id,last_seen) VALUES ($u1, NOW() - INTERVAL 4 DAY) ON DUPLICATE KEY UPDATE last_seen = NOW() - INTERVAL 4 DAY, idle_stage = 0, idle_at = NULL");
[, $b] = push_tick($conn, true);
ok($b >= 1 && count(app_inbox_take($conn, $u1)) === 1, 'the "we miss you" nudge lands in the app inbox');
$conn->exec("DELETE FROM users WHERE email IN ('aa1@t.local','aa2@t.local')"); push_on_user_deleted($conn, $u1); push_on_user_deleted($conn, $u2);
ok((int) $conn->query("SELECT COUNT(*) FROM app_devices WHERE user_id IN ($u1,$u2)")->fetchColumn() === 0, 'deleting a person removes their phones');
echo $f ? "app_alerts_test: $f FAILED\n" : "app_alerts_test: ALL PASSED\n"; exit($f ? 1 : 0);
