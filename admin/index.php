<?php
require_once __DIR__ . '/auth.php';

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
    ? $conn->query("SELECT l.action, l.details, l.created_at, u.name FROM user_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.action LIKE 'admin\\_%' ORDER BY l.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC)
    : [];

admin_page_start(['title' => 'Admin overview', 'sub' => 'How ExpenseFlow is doing', 'active' => 'ov']);
$stat = fn($label, $n, $icon) => '<div class="g card stat"><div><div class="k">' . e($label) . '</div><div class="big2">' . number_format($n) . '</div></div><div class="ibx">' . ef_icon($icon) . '</div></div>';
?>
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
      <?php $i = 0; foreach ($months as $m): $h = $m['n'] / $mx * 92; ?>
        <rect x="<?= 14 + $i * 48 ?>" y="<?= round(110 - $h, 1) ?>" width="30" height="<?= round($h, 1) ?>" rx="8" fill="var(--a1)" style="animation-delay:<?= $i * .08 ?>s"/>
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

<?php if ($audit): ?>
<div class="g card" style="margin-top:14px">
  <div class="t">Recent staff actions</div>
  <?php foreach ($audit as $a): ?>
  <div class="row"><span><?= e((string) $a['details']) ?><small><?= e($a['name'] ?? 'Former staff') ?> · <?= e(date('d M, H:i', strtotime((string) $a['created_at']))) ?></small></span></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_page_end();
