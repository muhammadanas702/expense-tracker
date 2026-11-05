<?php
// Part 61: one address for the site (https + canonical host). Run: php tests/redirect_test.php
require_once __DIR__ . '/../config/app.php';
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
$B = 'https://expenseflow.rf.gd';
ok(app_redirect_target($B, 'expenseflow.rf.gd', '/', false, false) === $B . '/?ef_https=1', 'plain http -> https');
ok(app_redirect_target($B, 'expenseflow.rf.gd', '/about.php?x=1', false, false) === $B . '/about.php?x=1&ef_https=1', 'query is kept');
ok(app_redirect_target($B, 'expenseflow.rf.gd', '/', true, false) === null, 'https is served');
ok(app_redirect_target($B, 'expenseflow.rf.gd', '/?ef_https=1', false, true) === null, 'marked request is never redirected again (no loop)');
ok(app_redirect_target($B, 'www.expenseflow.rf.gd', '/guides/', true, false) === $B . '/guides/', 'other host name goes to the one address');
ok(app_redirect_target($B, 'EXPENSEFLOW.rf.gd:80', '/', true, false) === null, 'host case and port are ignored');
ok(app_redirect_target($B, '127.0.0.1:8080', '/', false, false) === null && app_redirect_target($B, 'localhost', '/', false, false) === null, 'localhost is never redirected');
ok(app_redirect_target('http://127.0.0.1/x', 'foo.test', '/', false, false) === 'http://127.0.0.1/x/', 'an http base url does not force https');
ok(strpos(app_redirect_target($B, 'evil.example', "/a\r\nSet-Cookie: x=1", false, false), "\n") === false, 'no header injection through the path');
ok(app_redirect_target($B, 'expenseflow.rf.gd', 'no-slash', false, false) === $B . '/?ef_https=1', 'odd path becomes /');
echo $f ? "$f FAILED\n" : "redirect tests: ALL PASSED\n";
