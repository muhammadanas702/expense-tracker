<?php
/** Website health: live checks, page speed check, and an uptime history. Read-only apart from the history rows. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/health/lib.php';
health_ensure_schema($conn);

$dbMs = health_db_ms($conn);
$checks = health_checks($conn, $dbMs);
$overall = health_worst(array_column($checks, 2));
// Opening the page leaves one history row (at most one per 10 minutes).
health_record($conn, 'admin', $dbMs, $dbMs !== null && $overall !== 'bad', 600);

$hist = health_history($conn, 24);
$up = health_uptime($hist);
$spark = health_spark($hist);
$probe = $conn->query("SELECT created_at, detail FROM health_log WHERE kind = 'probe' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$probeRows = $probe ? (json_decode((string) $probe['detail'], true) ?: []) : [];
$recent = array_reverse(array_slice($hist, -14));
$tail = health_log_tail(15);
$when = fn(?string $t) => $t ? date('d M, H:i', strtotime($t)) : '–';
$src = ['admin' => 'Page opened', 'manual' => 'Checked by hand', 'ping' => 'Outside monitor'];
$col = ['ok' => '#0d9f6e', 'warn' => '#f59e0b', 'bad' => '#dc2626'];
$lbl = ['ok' => 'All good', 'warn' => 'Needs attention', 'bad' => 'Problem'];
$pingUrl = rtrim($base_url, '/') . '/health.php?db=1';

admin_page_start(['title' => 'Website health', 'sub' => $lbl[$overall], 'active' => 'hlth']);
?>
<div class="g card" style="margin-bottom:14px">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <span style="width:14px;height:14px;border-radius:50%;background:<?= e($col[$overall]) ?>;box-shadow:0 0 0 5px color-mix(in srgb,<?= e($col[$overall]) ?> 22%,transparent);flex:none"></span>
    <div style="flex:1;min-width:180px"><b id="hl-state" data-state="<?= e($overall) ?>" style="font-size:1.15rem"><?= e($lbl[$overall]) ?></b>
      <small class="k" style="display:block">Checked just now · database <?= $dbMs === null ? 'not answering' : e(number_format($dbMs, 1)) . ' ms' ?></small></div>
    <form method="post" action="health-action.php" class="toolbar" style="margin:0">
      <?= csrf_field() ?>
      <button class="btn sm" type="submit" name="do" value="probe">Run page speed check</button>
      <button class="btn sm ghost" type="submit" name="do" value="record">Save a check now</button>
    </form>
  </div>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Live checks</h3>
  <?php foreach ($checks as [$k, $v, $st, $hint]): ?>
  <div class="row" data-state="<?= e($st) ?>"><span><?= e($k) ?><small><?= e($hint) ?></small></span><b style="color:<?= e($col[$st]) ?>;text-align:right"><?= e($v) ?></b></div>
  <?php endforeach; ?>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Page speed check</h3>
<?php if ($probeRows): ?>
  <small class="k" style="display:block;margin-bottom:8px">Last run <?= e($when($probe['created_at'])) ?>. Each page was requested from this server, like a visitor would. Under 1.5 s is good.</small>
  <?php foreach ($probeRows as $r): ?>
  <div class="row" data-state="<?= e($r['state']) ?>"><span><?= e($r['path']) ?><?php if (!empty($r['error'])): ?><small><?= e($r['error']) ?></small><?php endif; ?></span><b style="color:<?= e($col[$r['state']] ?? $col['bad']) ?>;text-align:right"><?= $r['status'] ? 'HTTP ' . (int) $r['status'] . ' · ' . (int) $r['ms'] . ' ms' : 'No answer · ' . (int) $r['ms'] . ' ms' ?></b></div>
  <?php endforeach; ?>
<?php else: ?>
  <small class="k">Not run yet. Press "Run page speed check" to time the home page, login, register, guides and the health address (takes a few seconds).</small>
<?php endif; ?>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Last 24 hours</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:10px">
    <div><small class="k">Monitor pings</small><div style="font-size:1.4rem;font-weight:800"><?= (int) $up['pings'] ?></div></div>
    <div><small class="k">Failed pings</small><div style="font-size:1.4rem;font-weight:800"><?= (int) $up['failed'] ?></div></div>
    <div><small class="k">Longest silence</small><div style="font-weight:800;font-size:1.1rem"><?= $up['pings'] > 1 ? e(health_ago((int) $up['longest_gap'])) : '–' ?></div><?php if ($up['gap_at'] && $up['longest_gap'] > 900): ?><small class="k">after <?= e($when($up['gap_at'])) ?> (the site may have been down)</small><?php endif; ?></div>
  </div>
<?php if ($spark): ?>
  <small class="k">Database time over the last checks</small>
  <div style="color:var(--a1);margin:4px 0 12px"><?= $spark ?></div>
<?php endif; ?>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>When</th><th>Who</th><th>Database</th><th class="hm">Disk free</th><th>Result</th></tr>
<?php foreach ($recent as $r): ?>
    <tr><td class="nw"><?= e($when($r['created_at'])) ?></td><td><?= e($src[$r['source']] ?? $r['source']) ?></td><td><?= $r['db_ms'] !== null ? e(number_format((float) $r['db_ms'], 1)) . ' ms' : '–' ?></td><td class="hm"><?= $r['disk_pct'] !== null ? e($r['disk_pct']) . '%' : '–' ?></td><td><?= $r['ok'] ? '<span class="tag tg">OK</span>' : '<span class="tag tx">Problem</span>' ?></td></tr>
<?php endforeach; ?>
<?php if (!$recent): ?><tr><td colspan="5" class="k" style="padding:18px 12px">No history yet.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Watch the site 24/7 (free)</h3>
  <small class="k" style="display:block;margin-bottom:8px">This page can only show what the site itself sees. To know when the whole site is down, add a free monitor such as UptimeRobot or Better Stack: create an HTTP(s) monitor for this address and set the interval to 5 minutes. Every ping is saved above, and the monitor e-mails you when it fails.</small>
  <input class="inp" type="text" readonly value="<?= e($pingUrl) ?>" aria-label="Monitor address" onclick="this.select()">
</div>

<?php if ($tail): ?>
<div class="g card">
  <h3 style="margin:0 0 6px">Newest PHP errors</h3>
  <pre style="margin:0;white-space:pre-wrap;word-break:break-word;font-size:.78rem;line-height:1.5;max-height:260px;overflow:auto"><?= e(implode("\n", $tail)) ?></pre>
</div>
<?php endif; ?>
<?php admin_page_end();
