<?php
/**
 * Exchange-rate cache: a blocked or slow rate service must cost at most ONE short wait per 15 minutes, never one per page.
 * Usage: php tests/currency_cache_test.php (no database needed; it saves and restores storage/currency_cache.*)
 */
$root = dirname(__DIR__);
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
$dirs = [$root . '/storage', $root . '/storage/schema', sys_get_temp_dir() . '/ef-cache-' . md5($root . '/includes')];
$saved = [];
foreach ($dirs as $d) { foreach (['currency_cache.json', 'currency_cache.fail'] as $n) { if (is_file("$d/$n")) { $saved["$d/$n"] = [file_get_contents("$d/$n"), filemtime("$d/$n")]; unlink("$d/$n"); } } }
function run(string $url): array {
    global $root;
    $t = microtime(true);
    $cmd = 'EXPENSEFLOW_RATES_URL=' . escapeshellarg($url) . ' php -r ' . escapeshellarg("require '$root/includes/CurrencyConverter.php'; echo round(CurrencyConverter::convert(100, 'PKR', 'USD'), 4), '|', CurrencyConverter::usingFallback() ? 'fallback' : 'live';");
    $out = trim((string) shell_exec($cmd));
    return [$out, microtime(true) - $t];
}
$dead = 'http://10.255.255.1/rates'; // unroutable: connecting just hangs, like a host that blocks outgoing requests
[$o1, $t1] = run($dead);
ok(str_ends_with($o1, '|fallback'), "no cache + dead service: built-in rates ($o1)");
ok($t1 < 4.5, "first page waits at most about the 2 second limit ($t1 s)");
$failFiles = array_filter($dirs, fn($d) => is_file("$d/currency_cache.fail"));
ok(count($failFiles) === 1, 'the attempt was written down (' . count($failFiles) . ' fail file)');
[$o2, $t2] = run($dead);
ok($t2 < 1.0, "second page does not ask again ($t2 s)");
ok(str_ends_with($o2, '|fallback'), 'still answers with fallback rates');

// a stale cache keeps being used while the service is down
$d = array_values($failFiles)[0];
file_put_contents("$d/currency_cache.json", json_encode(['USD' => 1, 'PKR' => 200.0, 'EUR' => 0.9]));
touch("$d/currency_cache.json", time() - 7200); // older than the 1 hour freshness
touch("$d/currency_cache.fail", time() - 3600); // last attempt was 1 hour ago: asking again is allowed
[$o3, $t3] = run($dead);
ok(str_starts_with($o3, '0.5|'), "stale cache is used when the service is down ($o3)");
ok($t3 < 4.5, "one more short wait after the backoff ($t3 s)");
[$o4, $t4] = run($dead);
ok($t4 < 1.0 && str_starts_with($o4, '0.5|'), "and then quiet again ($t4 s)");

// a working service refreshes the cache and clears the failure note
$srv = proc_open(['php', '-S', '127.0.0.1:8099', '-t', $root . '/tests', $root . '/tests/mock_rates.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(700000);
touch("$d/currency_cache.fail", time() - 3600);
[$o5, $t5] = run('http://127.0.0.1:8099/rates');
ok(str_starts_with($o5, '0.25|live'), "live rates are used when the service answers ($o5)");
clearstatcache();
ok(!is_file("$d/currency_cache.fail"), 'failure note cleared after a good fetch');
proc_terminate($srv);

foreach ($dirs as $x) { foreach (['currency_cache.json', 'currency_cache.fail'] as $n) { @unlink("$x/$n"); } }
foreach ($saved as $f => [$c, $m]) { file_put_contents($f, $c); touch($f, $m); }
echo $fails ? "currency cache tests: $fails FAILED\n" : "currency cache tests: ALL PASSED\n";
exit($fails ? 1 : 0);
