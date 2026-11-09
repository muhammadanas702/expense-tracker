<?php
// Admin setting "E-mailed code when signing in": default off, only off/staff/all accepted, absent = unchanged.
require_once __DIR__ . '/../modules/settings/lib.php';
$f = 0;
function t(bool $c, string $m): void { global $f; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) { $f++; } }
t(SETTINGS_DEFAULTS['login_code'] === 'off', 'default is off (sign-in needs only the password)');
foreach (['off', 'staff', 'all'] as $m) {
    [$v, $e] = settings_validate(['login_code' => $m]);
    t($e === null && $v['login_code'] === $m, "accepts $m");
}
[$v, $e] = settings_validate(['login_code' => 'everyone-now']);
t($v === null && $e !== null, 'rejects an unknown value');
[$v, $e] = settings_validate([]);
t($e === null && !array_key_exists('login_code', $v), 'form without the field leaves the saved value alone');
echo $f ? "login_code_setting_test: $f FAILED\n" : "login_code_setting_test: ALL PASSED\n";
exit($f ? 1 : 0);
