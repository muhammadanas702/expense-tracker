<?php
/** Part 51: language helper and Urdu texts. Usage: php tests/i18n_test.php (no database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/i18n.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
$ur = require __DIR__ . '/../includes/lang/ur.php';

ok(ef_lang() === 'en' && ef_dir() === 'ltr' && t('Dashboard') === 'Dashboard', 'English by default');
ok(ef_html_attrs() === ' lang="en" dir="ltr"', 'html attributes for English');
ok(t('Welcome back, %s', 'Ali') === 'Welcome back, Ali', 'placeholders are filled in English');

// every Urdu text keeps the same %s / %% placeholders as its English key
foreach ($ur as $en => $u) {
    ok(preg_match_all('/%(?:%|s)/', $en) === preg_match_all('/%(?:%|s)/', $u), "placeholders match: $en");
    ok(trim($u) !== '', "not empty: $en");
}
// every literal t('...') in the translated pages has an Urdu text
$files = ['dashboard.php', 'includes/layout.php', 'modules/personal/tx-form.php', 'auth/login.php', 'settings/preferences.php'];
$missing = [];
foreach ($files as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    preg_match_all("/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
    foreach ($m[1] as $k) {
        $k = str_replace("\\'", "'", $k);
        if (!isset($ur[$k])) { $missing[$k] = $f; }
    }
}
foreach ($missing as $k => $f) { ok(false, "no Urdu text for '$k' ($f)"); }
// menu labels from the module manifests
foreach (glob(__DIR__ . '/../modules/*/module.php') as $mf) {
    $m = require $mf;
    if (($m['mode'] ?? 'p') !== 'p' && ($m['mode'] ?? '') !== 's') { continue; }
    foreach ($m['nav'] ?? [] as $n) {
        if (!in_array($m['id'], ['admin'], true)) { ok(isset($ur[$n[2]]) || str_starts_with($n[2], 'Add '), "menu label has Urdu text: {$n[2]}"); }
    }
}
echo $fails ? "i18n tests: $fails FAILED\n" : "i18n tests: ALL PASSED\n";
exit($fails ? 1 : 0);
