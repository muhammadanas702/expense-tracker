<?php
// Part 61: tools/change-domain.php on a temporary copy. Run: php tests/change_domain_test.php
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
$src = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/ef_dom_' . getmypid();
$files = ['config/app.php', '.well-known/security.txt', 'sitemap.xml', 'robots.txt', 'README.md', '.htaccess', 'apps/desktop/main.js', 'apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java', '.github/workflows/release.yml'];
foreach ($files as $x) { @mkdir(dirname("$tmp/$x"), 0777, true); copy("$src/$x", "$tmp/$x"); }
$run = fn(string $a) => shell_exec('php ' . escapeshellarg("$src/tools/change-domain.php") . " $a --root=" . escapeshellarg($tmp) . ' 2>&1');
$before = md5_file("$tmp/config/app.php");
$out = $run('new.example.com --dry');
ok(strpos($out, 'would change config/app.php') !== false && md5_file("$tmp/config/app.php") === $before, '--dry lists but changes nothing');
ok(strpos($run('"http://bad"'), 'Usage') !== false && md5_file("$tmp/config/app.php") === $before, 'a URL instead of a host name is refused');
ok(strpos($run("'a b.com'"), 'Usage') !== false, 'a host name with a space is refused');
$out = $run('Shop.Example.COM');
ok(strpos($out, 'Done:') !== false, 'runs');
foreach ($files as $x) {
    $s = file_get_contents("$tmp/$x");
    ok(strpos($s, 'expenseflow.rf.gd') === false, "$x has no old address left");
}
ok(strpos(file_get_contents("$tmp/config/app.php"), "'https://shop.example.com'") !== false, 'base url is the new address (lower case)');
ok(strpos(file_get_contents("$tmp/sitemap.xml"), 'https://shop.example.com/about.php') !== false, 'sitemap uses the new address');
ok(strpos(file_get_contents("$tmp/apps/android/app/src/main/java/pk/expenseflow/app/MainActivity.java"), 'HOST = "shop.example.com"') !== false, 'Android HOST changed');
ok(strpos($run('another.example.org'), 'Nothing to change') !== false, 'a second run finds nothing to change');
exec('rm -rf ' . escapeshellarg($tmp));
echo $f ? "$f FAILED\n" : "change domain tests: ALL PASSED\n";
