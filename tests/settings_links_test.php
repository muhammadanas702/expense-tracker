<?php
/** Apps & contact settings: validation, saving and the public (database-free) read. Usage: php tests/settings_links_test.php */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/settings/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
$before = settings_all($conn, true);
$file = SETTINGS_PUBLIC_FILE; $hadFile = is_file($file) ? file_get_contents($file) : null;

[$v, $err] = settings_validate(['link_android' => 'http://x.example/a.apk']);
ok($v === null && $err, 'http link refused');
[$v, $err] = settings_validate(['link_store' => 'javascript:alert(1)']);
ok($v === null, 'javascript link refused');
[$v, $err] = settings_validate(['contact_email' => 'not-mail']);
ok($v === null, 'bad e-mail refused');
[$v, $err] = settings_validate([]);
ok($err === null && $v['link_store'] === '' && $v['contact_email'] === SETTINGS_DEFAULTS['contact_email'] && $v['link_android'] === SETTINGS_DEFAULTS['link_android'], 'empty form gives defaults, Store link empty');

[$v, $err] = settings_validate(['signups' => '1', 'link_store' => 'https://apps.microsoft.com/detail/abc', 'link_android' => 'https://example.com/a.apk', 'contact_email' => ' Help@Example.com ']);
ok($err === null && $v['contact_email'] === 'help@example.com', 'valid values accepted, e-mail lower-cased');
$ch = settings_save($conn, $v);
ok(count($ch) === 3, 'three changes reported: ' . implode(',', $ch));
$j = json_decode(file_get_contents($file), true);
ok(($j['link_store'] ?? '') === 'https://apps.microsoft.com/detail/abc' && !isset($j['maintenance']), 'public file written with public keys only');

// restore
$orig = array_intersect_key($before, array_flip(['link_android', 'link_windows', 'link_store', 'contact_email']));
settings_save($conn, $orig + $before);
if ($hadFile === null) { @unlink($file); } else { file_put_contents($file, $hadFile); }
echo $fails ? "settings_links tests: $fails FAILED\n" : "settings_links tests: ALL PASSED\n";
exit($fails ? 1 : 0);
