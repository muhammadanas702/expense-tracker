<?php
// Part 67: Web Push (RFC 8291 encryption vector, subscriptions, delivery to a sink, bell hook, opt-out). Run: php tests/push_test.php
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/profile/lib.php';
require __DIR__ . '/../modules/activity/lib.php';
require __DIR__ . '/../modules/push/lib.php';
array_map('unlink', glob(__DIR__ . '/../storage/schema/*') ?: []);
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }

// RFC 8291 appendix A example
$asPriv = push_unb64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPub = push_unb64u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$der = hex2bin('30770201010420') . $asPriv . hex2bin('a00a06082a8648ce3d030107a144034200') . $asPub;
$pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
$body = push_encrypt('When I grow up, I want to be a watermelon', push_unb64u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), push_unb64u('BTBZMqHH6r4Tts7J_aSIgg'), push_unb64u('DGv6ra1nlYgDCS1FRnbzlw'), [$pem, $asPub]);
ok(push_b64u($body) === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', 'matches the RFC 8291 test vector');

// subscriptions
$conn->exec("DELETE FROM users WHERE email IN ('pu1@t.local','pu2@t.local')");
$mk = $conn->prepare("INSERT INTO users (name,email,password) VALUES (?,?,'x')");
$mk->execute(['P1', 'pu1@t.local']); $u1 = (int) $conn->lastInsertId();
$mk->execute(['P2', 'pu2@t.local']); $u2 = (int) $conn->lastInsertId();
profile_ensure_schema($conn); activity_ensure_schema($conn); push_ensure_schema($conn);
$conn->exec("DELETE FROM push_subscriptions");
[, $uaPub] = push_new_keypair();
$p256 = push_b64u($uaPub); $auth = push_b64u(random_bytes(16));
$ep = 'https://push.example.com/send/abc123';
ok(push_subscribe($conn, $u1, $ep, $p256, $auth), 'valid subscription saved');
ok(!push_subscribe($conn, $u1, 'http://push.example.com/x', $p256, $auth), 'plain http endpoint refused');
ok(!push_subscribe($conn, $u1, 'https://127.0.0.1/x', $p256, $auth), 'IP endpoint refused');
ok(!push_subscribe($conn, $u1, $ep . '2', 'AAAA', $auth), 'bad key refused');
ok(push_device_count($conn, $u1) === 1, 'one device');
push_subscribe($conn, $u1, $ep, $p256, $auth);
ok(push_device_count($conn, $u1) === 1, 'same endpoint is not duplicated');
push_subscribe($conn, $u2, 'https://push.example.com/send/u2', $p256, $auth);

// delivery goes to a sink file; the payload decrypts for the device
$sink = sys_get_temp_dir() . '/push_sink_' . getmypid(); @unlink($sink); putenv("EXPENSEFLOW_PUSH_SINK=$sink");
ok(push_notify($conn, [$u1], 'Title', 'Hello', 'activity.php') === 1, 'notify reports 1 accepted');
$line = json_decode((string) file_get_contents($sink), true);
ok(($line['endpoint'] ?? '') === $ep && str_starts_with($line['headers'][0] ?? '', 'Authorization: vapid t='), 'VAPID header present');
ok(in_array('Content-Encoding: aes128gcm', $line['headers'] ?? [], true) && strlen(base64_decode($line['body'] ?? '')) > 100, 'encrypted body present');
ok(strpos((string) file_get_contents($sink), 'Hello') === false, 'the text is not sent in clear');

// the bell hook pushes to people who have alerts on, skips the one who turned notifications off
$conn->prepare('INSERT INTO user_settings (user_id, notifications) VALUES (?,0) ON DUPLICATE KEY UPDATE notifications = 0')->execute([$u2]);
@unlink($sink);
$eid = activity_record($conn, 0, $u1, 'reminder_soon', ['title' => 'Rent', 'amount_minor' => 2, 'by' => 0], [$u1, $u2]);
$lines = is_file($sink) ? array_filter(explode("\n", (string) file_get_contents($sink))) : [];
ok($eid > 0 && count($lines) === 1 && (json_decode(reset($lines), true)['endpoint'] ?? '') === $ep, 'bell event is pushed to u1 only (u2 turned notifications off)');
ok(function_exists('push_after_event'), 'activity loads the push module on its own');

// unsubscribe and delete
push_unsubscribe($conn, $u1, $ep);
ok(push_device_count($conn, $u1) === 0, 'unsubscribed');
push_on_user_deleted($conn, $u2);
ok(push_device_count($conn, $u2) === 0, 'account deletion removes devices');
$conn->exec("DELETE FROM users WHERE id IN ($u1,$u2)"); $conn->exec("DELETE FROM user_settings WHERE user_id IN ($u1,$u2)"); @unlink($sink);
echo $f ? "$f FAILED\n" : "push tests: ALL PASSED\n";
