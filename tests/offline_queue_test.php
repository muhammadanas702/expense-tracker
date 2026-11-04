<?php
// Part 54: helpers of the offline expense queue. Run: php tests/offline_queue_test.php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/personal/lib.php';
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
ok(personal_client_id('0123456789abcdef0123456789abcdef') === '0123456789abcdef0123456789abcdef', '32 hex ok');
ok(personal_client_id('0123456789abcdef') !== null, '16 hex ok');
ok(personal_client_id('short') === null && personal_client_id('XYZ3456789abcdef0123') === null && personal_client_id(null) === null && personal_client_id(['a']) === null, 'bad ids refused');
ok(personal_client_id("0123456789abcdef'; DROP") === null, 'injection refused');
$n = 1_800_000_000;
ok(personal_client_time((string) ($n - 3600), $n) === $n - 3600, 'an hour ago is accepted');
ok(personal_client_time((string) ($n - 20 * 86400), $n) === null, 'older than 14 days refused');
ok(personal_client_time((string) ($n + 3600), $n) === null, 'the future is refused');
ok(personal_client_time('abc', $n) === null && personal_client_time(null, $n) === null && personal_client_time([], $n) === null, 'garbage refused');
echo $f ? "$f FAILED\n" : "offline queue tests: ALL PASSED\n";
