<?php
/** Speed check: shows where time goes on this host (database round trip, schema markers, cold schema checks). Read-only. */
$__t0 = microtime(true);
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/shared/lib.php';
require_once __DIR__ . '/../includes/schema.php';

// 1. Database round trip: ten trivial queries.
$times = [];
for ($i = 0; $i < 10; $i++) {
    $t = microtime(true);
    $conn->query('SELECT 1')->fetchColumn();
    $times[] = (microtime(true) - $t) * 1000;
}
$dbAvg = array_sum($times) / count($times);
$dbMax = max($times);

// 2. Schema markers: these let every page skip its "create table / add column" checks.
$markers = [];
foreach (ef_schema_dirs() as $d) {
    $markers[] = ['dir' => str_replace(dirname(__DIR__) . '/', '', realpath($d) ?: $d), 'exists' => is_dir($d), 'writable' => is_dir($d) && is_writable($d), 'count' => is_dir($d) ? count(glob($d . '/*.ok') ?: []) : 0];
}
$working = array_sum(array_column($markers, 'count')) > 0;

// 3. A cold run of the schema checks (what EVERY page would pay if the markers did not work).
putenv('EXPENSEFLOW_NO_SCHEMA_CACHE=1');
$checks = ['personal_ensure_schema', 'budgets_ensure_schema', 'goals_ensure_schema', 'reminders_ensure_schema', 'activity_ensure_schema', 'profile_ensure_schema', 'oauth_ensure_schema', 'settings_ensure_schema'];
$cold = [];
foreach ($checks as $fn) {
    if (function_exists($fn)) {
        $t = microtime(true);
        try { $fn($conn); } catch (Throwable $e) { error_log('speed check: ' . $e->getMessage()); }
        $cold[$fn] = (microtime(true) - $t) * 1000;
    }
}
putenv('EXPENSEFLOW_NO_SCHEMA_CACHE');

// 4. Other things that can slow every request down.
$storage = __DIR__ . '/../storage';
$sessions = is_dir($storage . '/sessions') ? count(glob($storage . '/sessions/sess_*') ?: []) : null;
$cacheFile = $storage . '/currency_cache.json';
$cacheAge = is_file($cacheFile) ? time() - (int) filemtime($cacheFile) : null;
$failAge = is_file($storage . '/currency_cache.fail') ? time() - (int) filemtime($storage . '/currency_cache.fail') : null;
$opcache = function_exists('opcache_get_status') && ini_get('opcache.enable');
$modules = count(glob(__DIR__ . '/../modules/*/module.php') ?: []);

$ms = fn($v) => number_format($v, 1) . ' ms';
$rows = [
    ['Database round trip', $ms($dbAvg) . ' average, ' . $ms($dbMax) . ' slowest (10 tries)', $dbAvg > 40 ? 'warn' : 'ok', 'A normal page makes about 25 queries, so 25 x this number is the time the database costs per page.'],
    ['Schema markers', $working ? 'Working (' . array_sum(array_column($markers, 'count')) . ' saved)' : 'NOT working: every page repeats its schema checks', $working ? 'ok' : 'bad', 'If this says NOT working, every page repeats its database checks, which makes the whole site slower.'],
    ['Cold schema checks (all modules)', $ms(array_sum($cold)), array_sum($cold) > 400 ? 'warn' : 'ok', 'This is what each page would pay on top if the markers failed.'],
    ['PHP', PHP_VERSION . ($opcache ? ', OPcache on' : ', OPcache off'), 'ok', ''],
    ['Modules loaded per page', (string) $modules, 'ok', ''],
    ['Session files', $sessions === null ? 'not used' : (string) $sessions, ($sessions ?? 0) > 5000 ? 'warn' : 'ok', 'Thousands of old session files make every page slower.'],
    ['Exchange-rate cache', $cacheAge === null ? 'none (built-in rates are used)' : 'updated ' . round($cacheAge / 3600, 1) . ' h ago' . ($failAge !== null ? ', last live fetch failed ' . round($failAge / 60) . ' min ago' : ''), 'ok', ''],
];
admin_page_start(['title' => 'Speed check', 'sub' => 'Where does the time go?', 'active' => 'spd']);
$col = ['ok' => '#0d9f6e', 'warn' => '#f59e0b', 'bad' => '#dc2626'];
?>
<div class="g card" style="margin-bottom:14px">
  <?php foreach ($rows as [$k, $v, $st, $hint]): ?>
  <div class="row" data-state="<?= e($st) ?>"><span><?= e($k) ?><small><?= e($hint) ?></small></span><b style="color:<?= e($col[$st]) ?>"><?= e($v) ?></b></div>
  <?php endforeach; ?>
</div>
<div class="g card" style="margin-bottom:14px">
  <div class="t">Marker folders</div>
  <?php foreach ($markers as $m): ?>
  <div class="row"><span><?= e($m['dir']) ?><small><?= $m['exists'] ? ($m['writable'] ? 'writable' : 'NOT writable') : 'missing' ?></small></span><b><?= (int) $m['count'] ?> saved</b></div>
  <?php endforeach; ?>
</div>
<div class="g card">
  <div class="t">Cold schema check per module</div>
  <?php foreach ($cold as $fn => $v): ?>
  <div class="row"><span><?= e($fn) ?></span><b><?= e($ms($v)) ?></b></div>
  <?php endforeach; ?>
</div>
<?php admin_page_end();
