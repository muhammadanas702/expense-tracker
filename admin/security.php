<?php
require_once __DIR__ . '/auth.php';
admin_need('log');
require_once __DIR__ . '/../modules/security/lib.php';
security_ensure_schema($conn);

$kind = isset(SECURITY_EVENT_KINDS[$_GET['k'] ?? '']) ? $_GET['k'] : '';
$page = max(1, (int) ($_GET['p'] ?? 1));
$stats = security_stats($conn);
$blocks = security_active_blocks($conn);
$top = security_top_ips($conn);
[$events, $total] = security_recent($conn, $kind, $page);
$pages = max(1, (int) ceil($total / 25));
$canBlock = admin_can($conn, $admin['role'], 'block');
$blockedNow = array_column($blocks, 'ip');
$url = fn(array $o = []) => 'security.php?' . http_build_query(array_filter(array_merge(['k' => $kind, 'p' => $page > 1 ? $page : ''], $o), fn($v) => $v !== '' && $v !== null));
$when = fn(?string $t) => $t ? date('d M, H:i', strtotime($t)) : '';

admin_page_start(['title' => 'Security', 'sub' => $stats['fail24'] . ' failed sign-ins in 24 hours', 'active' => 'sec']);
?>
<div class="g card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px">
    <div><small class="k">Failed sign-ins (24 h)</small><div style="font-size:1.5rem;font-weight:800"><?= (int) $stats['fail24'] ?></div></div>
    <div><small class="k">Lock-outs (24 h)</small><div style="font-size:1.5rem;font-weight:800"><?= (int) $stats['locked24'] ?></div></div>
    <div><small class="k">Failed (7 days)</small><div style="font-size:1.5rem;font-weight:800"><?= (int) $stats['fail7'] ?></div></div>
    <div><small class="k">Blocked addresses</small><div style="font-size:1.5rem;font-weight:800"><?= (int) $stats['blocks'] ?></div></div>
    <div><small class="k">Blocked accounts</small><div style="font-size:1.5rem;font-weight:800"><?= (int) $stats['users'] ?></div></div>
  </div>
  <small class="k" style="display:block;margin-top:10px">Wrong-password attempts are limited automatically. Here you can also block a whole address. A block only stops sign-in, sign-up and password-reset emails from it. Emails are shown with the name hidden (like s***@example.com).</small>
</div>


<?php if ($canBlock): ?>
<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 10px">Block an address</h3>
  <form method="post" action="security-action.php" class="toolbar">
    <?= csrf_field() ?>
    <input class="inp" name="ip" placeholder="IP address, e.g. 203.0.113.7" maxlength="45" required aria-label="IP address">
    <input class="inp" name="reason" placeholder="Reason (optional)" maxlength="100" aria-label="Reason">
    <select class="inp" name="duration" style="max-width:160px" aria-label="For how long"><?php foreach (SECURITY_DURATIONS as $k => $d): ?><option value="<?= e($k) ?>"<?= $k === '1d' ? ' selected' : '' ?>><?= e($d[0]) ?></option><?php endforeach; ?></select>
    <button class="btn sm" type="submit" name="do" value="block">Block</button>
  </form>
</div>
<?php endif; ?>

<?php if ($top): ?>
<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Most failed attempts (7 days)</h3>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Address</th><th>Attempts</th><th>Last</th><th></th></tr>
    <?php foreach ($top as $t): ?>
    <tr>
      <td><b><?= e($t['ip']) ?></b></td>
      <td><?= (int) $t['n'] ?> tries</td>
      <td class="hm nw"><?= e($when($t['last_at'])) ?></td>
      <td class="nw">
        <?php if (in_array($t['ip'], $blockedNow, true)): ?><span class="tag tu">Blocked</span>
        <?php elseif ($canBlock): ?><form method="post" action="security-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="ip" value="<?= e($t['ip']) ?>"><input type="hidden" name="duration" value="1d"><input type="hidden" name="reason" value="Many failed attempts">
          <button class="btn sm o" name="do" value="block" type="submit">Block 1 day</button></form><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 6px">Blocked addresses</h3>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Address</th><th>Until</th><th class="hm">Reason</th><th></th></tr>
    <?php foreach ($blocks as $b): ?>
    <tr>
      <td><b><?= e($b['ip']) ?></b><small class="dt-m"><?= e($b['reason'] ?: 'No reason') ?> · by <?= e(($b['by_role'] ?? '') === 'super' && $admin['role'] !== 'super' ? 'Super Admin' : ($b['by_name'] ?? 'staff')) ?></small></td>
      <td class="nw"><?= $b['expires_at'] ? e($when($b['expires_at'])) : 'Until removed' ?></td>
      <td class="hm"><?= e($b['reason'] ?: '–') ?> <small>by <?= e(($b['by_role'] ?? '') === 'super' && $admin['role'] !== 'super' ? 'Super Admin' : ($b['by_name'] ?? 'staff')) ?></small></td>
      <td class="nw"><?php if ($canBlock): ?><form method="post" action="security-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="ip" value="<?= e($b['ip']) ?>">
        <button class="btn sm o" name="do" value="unblock" type="submit">Unblock</button></form><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$blocks): ?><tr><td colspan="4" class="k" style="padding:18px 12px">No blocked addresses.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="g card">
  <h3 style="margin:0 0 8px">Recent events</h3>
  <div class="chips" style="margin-bottom:12px">
    <a class="chip<?= $kind === '' ? ' on' : '' ?>" href="<?= e($url(['k' => '', 'p' => ''])) ?>">All</a>
    <?php foreach (SECURITY_EVENT_KINDS as $k => $l): ?><a class="chip<?= $kind === $k ? ' on' : '' ?>" href="<?= e($url(['k' => $k, 'p' => ''])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>What</th><th>Address</th><th class="hm">Account tried</th><th>When</th></tr>
    <?php foreach ($events as $ev): ?>
    <tr>
      <td><b><?= e(SECURITY_EVENT_KINDS[$ev['kind']] ?? $ev['kind']) ?></b><small class="dt-m"><?= e($ev['who']) ?></small></td>
      <td><?= e($ev['ip']) ?></td>
      <td class="hm"><?= e($ev['who'] ?: '–') ?></td>
      <td class="nw"><?= e($when($ev['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$events): ?><tr><td colspan="4" class="k" style="padding:18px 12px">Nothing recorded yet. Events are kept for 30 days.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="chips" style="margin-top:12px;justify-content:center">
    <?php if ($page > 1): ?><a class="chip" href="<?= e($url(['p' => $page - 1])) ?>">← Newer</a><?php endif; ?>
    <span class="chip">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="chip" href="<?= e($url(['p' => $page + 1])) ?>">Older →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_page_end();
