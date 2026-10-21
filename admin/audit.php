<?php
require_once __DIR__ . '/auth.php';
admin_need('log');

$group = isset(admin_audit_groups()[$_GET['g'] ?? '']) ? $_GET['g'] : 'all';
$q = trim((string) ($_GET['q'] ?? ''));
$from = valid_date($_GET['from'] ?? '') ?? '';
$to = valid_date($_GET['to'] ?? '') ?? '';
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 30;

[$where, $params] = admin_audit_where($group);
$where = [$where];
if ($q !== '') {
    $where[] = '(l.details LIKE ? OR u.name LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like);
}
if ($from !== '') { $where[] = 'DATE(l.created_at) >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'DATE(l.created_at) <= ?'; $params[] = $to; }
$w = 'WHERE ' . implode(' AND ', $where) . admin_hide_super_sql($admin);

$cnt = $conn->prepare("SELECT COUNT(*) FROM user_logs l LEFT JOIN users u ON u.id = l.user_id $w");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$stmt = $conn->prepare("SELECT l.action, l.details, l.ip_address, l.created_at, u.name FROM user_logs l LEFT JOIN users u ON u.id = l.user_id $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$url = fn(array $o = []) => 'audit.php?' . http_build_query(array_filter(array_merge(['g' => $group === 'all' ? '' : $group, 'q' => $q, 'from' => $from, 'to' => $to, 'p' => $page > 1 ? $page : ''], $o), fn($v) => $v !== '' && $v !== null));

admin_page_start(['title' => 'Audit log', 'sub' => 'What staff did, newest first · ' . $total . ' entr' . ($total === 1 ? 'y' : 'ies'), 'active' => 'log']);
?>
<div class="g card">
  <form method="get" class="toolbar">
    <?php if ($group !== 'all'): ?><input type="hidden" name="g" value="<?= e($group) ?>"><?php endif; ?>
    <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Search details or staff name" maxlength="60">
    <input class="inp" type="date" name="from" value="<?= e($from) ?>" aria-label="From date" style="flex:0 0 150px">
    <input class="inp" type="date" name="to" value="<?= e($to) ?>" aria-label="To date" style="flex:0 0 150px">
    <button class="btn sm" type="submit">Filter</button>
    <a class="btn sm o" href="audit.php">Clear</a>
  </form>
  <div class="chips" style="margin-bottom:12px">
    <?php foreach (admin_audit_groups() as $k => $l): ?><a class="chip<?= $group === $k ? ' on' : '' ?>" href="<?= e($url(['g' => $k === 'all' ? '' : $k, 'p' => ''])) ?>"><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl fixm">
    <tr><th>When</th><th class="hm">Staff</th><th>Action</th><th class="hm">Details</th><th class="hm">IP</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nw"><?= e(date('d M Y, H:i', strtotime((string) $r['created_at']))) ?></td>
      <td class="hm"><?= e($r['name'] ?? 'Former staff') ?></td>
      <td><span class="tag tb2"><?= e(admin_audit_label($r['action'])) ?></span><small class="dt-m"><?= e($r['name'] ?? 'Former staff') ?>: <?= e((string) $r['details']) ?></small></td>
      <td class="hm"><?= e((string) $r['details']) ?></td>
      <td class="hm"><?= e((string) $r['ip_address']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="k" style="padding:18px 12px">No entries match.</td></tr><?php endif; ?>
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
