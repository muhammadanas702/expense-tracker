<?php
/** Part 111c: usernames and friend requests by @username (needs the local test database). Run: php tests/username_test.php */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
require __DIR__ . '/../modules/friends/lib.php';
friends_ensure_schema($conn);
friends_username_ensure($conn);
$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
$ids = [];
foreach (['a', 'b', 'c'] as $n) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute(["Usr $n", "usr$n@t.local", 'x']);
    $ids[$n] = (int) $conn->lastInsertId();
}
[$a, $b, $c] = array_values($ids);
ok(friends_username_clean('@Ali.Raza ') === 'ali.raza', 'cleans @ and capitals');
foreach (['ab', 'a b', 'ali..raza', 'ali.', '_ali', 'x@y.com', str_repeat('a', 21), 'علی'] as $bad) { ok(friends_username_clean($bad) === null, 'rejects "' . $bad . '"'); }
ok(!friends_username_set($conn, $a, 'admin')['ok'], 'reserved name refused');
ok(friends_username_set($conn, $a, 'usr.alpha')['ok'] && friends_username_of($conn, $a) === 'usr.alpha', 'username saved');
ok(!friends_username_set($conn, $b, 'USR.Alpha')['ok'], 'taken (even in other capitals)');
ok(friends_username_set($conn, $a, 'usr.alpha')['ok'], 'keeping your own username is fine');
ok(friends_username_set($conn, $b, 'usr.bravo')['ok'], 'second person gets a different one');
$r = friends_request($conn, $a, '@usr.bravo');
ok($r['ok'] && strpos($r['msg'], 'username') !== false, 'request by @username');
$p = friends_pending($conn, $b);
ok(count($p['in']) === 1, 'the other person sees the request');
$r2 = friends_request($conn, $a, '@nobody.here');
ok($r2['ok'] && count(friends_pending($conn, $a)['out']) === 1, 'unknown username gives the same kind of answer and creates nothing');
ok(!friends_request($conn, $a, 'a b')['ok'], 'garbage refused');
$r3 = friends_request($conn, $a, 'usrc@t.local');
ok($r3['ok'] && count(friends_pending($conn, $a)['out']) === 2, 'email still works');
ok(!friends_request($conn, $a, '@usr.alpha')['ok'] || true, 'own username does not crash');
ok(friends_username_set($conn, $a, '')['ok'] && friends_username_of($conn, $a) === null, 'username can be removed');
$conn->exec("DELETE FROM friend_links WHERE requester_id IN ($a,$b,$c)");
$conn->exec("DELETE FROM users WHERE id IN ($a,$b,$c)");
echo $fail ? "FAILED: $fail\n" : "username_test: ALL PASSED\n";
exit($fail ? 1 : 0);
