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
$files = ['dashboard.php', 'includes/layout.php', 'modules/personal/tx-form.php', 'auth/login.php', 'settings/preferences.php', 'budgets.php', 'goals.php', 'reminders.php',
    'auth/register.php', 'auth/forgot-password.php', 'auth/set-new-password.php', 'auth/verify-otp.php', 'auth/verify-email.php', 'includes/auth-layout.php'];
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
// sign-in / sign-up pages: messages, field labels, placeholders and checklist lines go through ef_msg / ef_field / ef_check, which translate them
$authFiles = ['auth/register.php', 'auth/forgot-password.php', 'auth/set-new-password.php', 'auth/verify-otp.php', 'auth/verify-email.php', 'auth/login.php', 'auth/login-code.php', 'auth/send-otp.php'];
foreach ($authFiles as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    $found = [];
    preg_match_all("/\\\$(?:msg|error|err)\\s*=\\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1\\s*;/", $src, $m); foreach ($m[2] as $k) { $found[] = $k; }
    preg_match_all("/_message'\\]\\s*=\\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/", $src, $m); foreach ($m[2] as $k) { $found[] = $k; }
    preg_match_all("/ef_field\\('([^']+)'.*?'placeholder' => '([^']*)'/", $src, $m); foreach (array_merge($m[1], $m[2]) as $k) { $found[] = $k; }
    preg_match_all("/ef_check\\('([^']+)'/", $src, $m); foreach ($m[1] as $k) { $found[] = $k; }
    foreach ($found as $k) {
        $k = str_replace(["\\'", '\\"'], ["'", '"'], $k);
        if ($k !== '' && strpos($k, '$') === false) { ok(isset($ur[$k]), "auth text has Urdu: '$k' ($f)"); }
    }
}
// menu labels from the module manifests
foreach (glob(__DIR__ . '/../modules/*/module.php') as $mf) {
    $m = require $mf;
    if (($m['mode'] ?? 'p') !== 'p' && ($m['mode'] ?? '') !== 's') { continue; }
    foreach ($m['nav'] ?? [] as $n) {
        if (!in_array($m['id'], ['admin'], true)) { ok(isset($ur[$n[2]]) || str_starts_with($n[2], 'Add '), "menu label has Urdu text: {$n[2]}"); }
    }
}
// Part 62: the output filter translates exact dictionary hits in text, placeholders and titles, and leaves scripts/JSON alone
$_COOKIE['ef_lang'] = 'ur';
$out = ef_i18n_page('<html><body><h1>Budgets</h1><input placeholder="Search friends" title="Save"><script>var a="Budgets";</script><p>Unknown words here</p></body></html>');
ok(strpos($out, '<h1>' . $ur['Budgets'] . '</h1>') !== false, 'filter translates text nodes');
ok(strpos($out, 'placeholder="' . $ur['Search friends'] . '"') !== false, 'filter translates placeholders');
ok(strpos($out, 'var a="Budgets";') !== false, 'filter leaves scripts alone');
ok(strpos($out, 'Unknown words here') !== false, 'filter keeps unknown English');
ok(ef_i18n_page('{"a":"Budgets"}') === '{"a":"Budgets"}', 'filter leaves JSON alone');
ok(trim((string) shell_exec('EF_X=1 php -r ' . escapeshellarg('$_GET["lang"]="ur"; require "' . __DIR__ . '/../includes/i18n.php"; echo ef_month_year(new DateTime("2026-11-06"));'))) === 'نومبر 2026', 'month name in Urdu');
echo $fails ? "i18n tests: $fails FAILED\n" : "i18n tests: ALL PASSED\n";
exit($fails ? 1 : 0);
