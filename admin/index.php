<?php
require_once __DIR__ . '/auth.php';

if (admin_can($conn, $admin['role'], 'backup')) {
    require_once __DIR__ . '/../modules/backup/lib.php';
    if (backup_auto_maybe($conn)) {
        admin_audit($conn, $admin['id'], 'backup_create', 'Automatic daily database backup');
    }
}

$one = fn(string $sql, array $p = []) => (function () use ($conn, $sql, $p) { try { $s = $conn->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); } catch (Throwable $e) { return 0; } })();
$total = $one('SELECT COUNT(*) FROM users');
$newMonth = $one("SELECT COUNT(*) FROM users WHERE created_at >= ?", [date('Y-m-01 00:00:00')]);
$active7 = $one("SELECT COUNT(DISTINCT user_id) FROM user_logs WHERE action = 'login' AND created_at >= ?", [date('Y-m-d H:i:s', strtotime('-7 days'))]);
$staff = $one("SELECT COUNT(*) FROM users WHERE role IN ('super','admin','support')");
$groups = $one('SELECT COUNT(*) FROM shared_groups');
$entries = $one('SELECT COUNT(*) FROM expenses') + $one('SELECT COUNT(*) FROM income');

// sign-ups per month, last 6 months
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $d = strtotime("first day of -$i month");
    $months[date('Y-m', $d)] = ['label' => date('M', $d), 'n' => 0];
}
foreach ($conn->query("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n FROM users WHERE created_at >= '" . date('Y-m-01', strtotime('-5 months')) . "' GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($months[$r['ym']])) { $months[$r['ym']]['n'] = (int) $r['n']; }
}
$mx = max(1, ...array_column($months, 'n'));
$recent = $conn->query("SELECT id, name, email, role, created_at FROM users ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$canView = admin_can($conn, $admin['role'], 'view');
$audit = admin_can($conn, $admin['role'], 'log')
    ? $conn->query("SELECT l.action, l.details, l.created_at, u.name FROM user_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.action LIKE 'admin\\_%'" . admin_hide_super_sql($admin) . " ORDER BY l.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC)
    : [];

require_once __DIR__ . '/../modules/admin/attention.php';
$attention = admin_attention($conn, $admin);
$hub = admin_visible_menu($conn, $admin['role']);
unset($hub['Overview']);
admin_page_start(['title' => 'Admin overview', 'sub' => 'How ExpenseFlow is doing', 'active' => 'ov']);
$stat = fn($label, $n, $icon) => '<div class="g card stat"><div><div class="k">' . e($label) . '</div><div class="big2">' . number_format($n) . '</div></div><div class="ibx">' . ef_icon($icon) . '</div></div>';
?>
<?php $nBad = count(array_filter($attention, fn($a) => $a['level'] !== 'info')); ?>
<div class="g card attn <?= $attention ? ($nBad ? 'has' : 'info') : 'ok' ?>" id="attention">
  <div class="t"><?= ef_icon($attention ? 'bell' : 'ck') ?> <?= $attention ? 'Needs your attention' . ($nBad ? ' (' . $nBad . ')' : '') : 'All clear' ?></div>
<?php if (!$attention): ?>
  <p class="k" style="margin:0">Nothing needs your attention right now: the website is healthy, no one is waiting for an answer and the backup is recent.</p>
<?php else: foreach ($attention as $a): ?>
  <div class="attn-row <?= e($a['level']) ?>">
    <i aria-hidden="true"></i>
    <span><b><?= e($a['title']) ?></b><small><?= e($a['detail']) ?></small></span>
    <a class="btn sm" href="<?= e(ef_url($a['url'])) ?>"><?= e($a['go']) ?></a>
  </div>
<?php endforeach; endif; ?>
</div>
<style>
.attn{margin-bottom:14px;padding:14px 16px}
.attn.ok{border-inline-start:4px solid #16a34a}.attn.has{border-inline-start:4px solid #dc2626}.attn.info{border-inline-start:4px solid #2563eb}
.attn .t{display:flex;align-items:center;justify-content:flex-start;gap:8px;margin-bottom:6px}
.attn-row{display:flex;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--line,rgba(120,130,150,.22))}
.attn-row:first-of-type{border-top:0}
.attn-row i{flex:none;width:10px;height:10px;border-radius:50%}
.attn-row.bad i{background:#dc2626}.attn-row.warn i{background:#f59e0b}.attn-row.info i{background:#2563eb}
.attn-row span{flex:1;min-width:0;display:block}
.attn-row b{display:block;line-height:1.3}
.attn-row small{display:block;margin-top:2px;line-height:1.4;opacity:.8}
.attn-row .btn{flex:none;white-space:nowrap}
@media(max-width:560px){.attn-row{flex-wrap:wrap;row-gap:8px}.attn-row span{flex:1 1 calc(100% - 24px)}.attn-row .btn{margin-inline-start:22px}}
</style>
<div class="G4a">
  <?= $stat('Total users', $total, 'users') ?>
  <?= $stat('New this month', $newMonth, 'pl') ?>
  <?= $stat('Active (7 days)', $active7, 'act') ?>
  <?= $stat('Staff members', $staff, 'shield') ?>
  <?= $stat('Shared groups', $groups, 'users') ?>
  <?= $stat('Income + expense entries', $entries, 'doc') ?>
</div>

<div class="G2" style="margin-top:14px">
  <div class="g card">
    <div class="t">New users per month</div>
    <svg class="barchart" viewBox="0 0 300 130" role="img" aria-label="New users per month">
      <line x1="6" y1="110.5" x2="294" y2="110.5" stroke="var(--mu)" stroke-opacity=".25" stroke-width="1"/>
      <?php $i = 0; foreach ($months as $m): $h = $m['n'] / $mx * 92; ?>
        <rect x="<?= 14 + $i * 48 ?>" y="<?= round(110 - max($h, 3), 1) ?>" width="30" height="<?= round(max($h, 3), 1) ?>" rx="<?= $h < 8 ? 1.5 : 8 ?>" fill="var(--a1)" fill-opacity="<?= $m['n'] > 0 ? 1 : .28 ?>" style="animation-delay:<?= $i * .08 ?>s"/>
        <text x="<?= 29 + $i * 48 ?>" y="<?= round(104 - $h, 1) ?>" text-anchor="middle"><?= (int) $m['n'] ?></text>
        <text class="m" x="<?= 29 + $i * 48 ?>" y="125" text-anchor="middle"><?= e($m['label']) ?></text>
      <?php $i++; endforeach; ?>
    </svg>
  </div>
  <div class="g card">
    <div class="t">Latest sign-ups<?php if ($canView): ?> <a href="<?= e(ef_url('admin/users.php')) ?>">All users →</a><?php endif; ?></div>
    <?php foreach ($recent as $u): ?>
    <div class="row"><i class="av" style="background:#1d4ed8"><?= e(ef_initials($u['name'])) ?></i>
      <span><?= e($u['name']) ?><small><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></small></span>
      <?php if ($u['role'] !== 'user'): ?><span class="tag <?= $u['role'] === 'super' ? 'rs' : 'tb2' ?>"><?= e(admin_role_label($u['role'])) ?></span><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="g card" style="margin-top:14px">
  <div class="t">What do you want to do? <a href="<?= e(ef_url('admin/help.php')) ?>">Not sure? Where do I go? →</a></div>
  <div class="ahub">
<?php foreach ($hub as $section => $items): ?>
    <div class="ahs"><b><?= e($section) ?></b><small><?= e(admin_section_info()[$section] ?? '') ?></small>
<?php foreach ($items as $i): ?>
      <a href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><span><?= e($i[3]) ?><small><?= e($i[5] ?? '') ?></small></span></a>
<?php endforeach; ?>
    </div>
<?php endforeach; ?>
    <div class="ahs ahs-tip"><b>Not sure where to go?</b><small>Describe the problem in your own words.</small>
      <a href="<?= e(ef_url('admin/help.php')) ?>"><?= ef_icon('lifebuoy') ?><span>Where do I go?<small>"Login code not arriving", "website is slow", "change the logo"... we point you to the right page.</small></span></a>
    </div>
  </div>
</div>

<?php if ($audit): ?>
<div class="g card" style="margin-top:14px">
  <div class="t">Recent staff actions</div>
  <?php foreach ($audit as $a): ?>
  <div class="row"><span><?= e((string) $a['details']) ?><small><?= e($a['name'] ?? 'Former staff') ?> · <?= e(date('d M, H:i', strtotime((string) $a['created_at']))) ?></small></span></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_page_end();
