<?php
require_once "auth.php";
require_once "../config/db.php";
admin_need('log');

$user_filter = filter_var($_GET['user_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$user_filter = $user_filter === false ? '' : $user_filter;
$action_filter = preg_match('/^[a-z_]{1,40}$/', (string)($_GET['action'] ?? '')) ? $_GET['action'] : '';
$from_date = valid_date($_GET['from_date'] ?? '') ?? '';
$to_date = valid_date($_GET['to_date'] ?? '') ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($user_filter) {
    $where[] = "l.user_id = ?";
    $params[] = $user_filter;
}
if ($action_filter) {
    $where[] = "l.action = ?";
    $params[] = $action_filter;
}
if ($from_date) {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $to_date;
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM user_logs l $where_sql");
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total_pages = (int) ceil($total / $limit);

$sql = "SELECT l.*, u.name as user_name 
        FROM user_logs l 
        JOIN users u ON l.user_id = u.id 
        $where_sql 
        ORDER BY l.created_at DESC 
        LIMIT " . (int) $offset . ", " . (int) $limit;
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = $conn->query("SELECT id, name, email FROM users ORDER BY name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$actions = $conn->query("SELECT DISTINCT action FROM user_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$link = fn(array $o = []) => 'logs.php?' . http_build_query(array_filter(array_merge($_GET, $o), fn($v) => $v !== '' && $v !== null));

admin_page_start(['title' => 'User logs', 'sub' => 'Everything users did · ' . (int) $total . ' entries', 'active' => 'ulog']);
?>
<div class="g card">
  <form method="get" class="toolbar">
    <select class="inp" name="user_id" aria-label="User" style="flex:1 1 180px">
      <option value="">All users</option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $user_filter === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option><?php endforeach; ?>
    </select>
    <select class="inp" name="action" aria-label="Action" style="flex:1 1 160px">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $action_filter === $a ? ' selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', preg_replace('/^admin_/', 'admin: ', $a)))) ?></option><?php endforeach; ?>
    </select>
    <input class="inp" type="date" name="from_date" value="<?= e($from_date) ?>" aria-label="From date" style="flex:0 0 150px">
    <input class="inp" type="date" name="to_date" value="<?= e($to_date) ?>" aria-label="To date" style="flex:0 0 150px">
    <button class="btn sm" type="submit">Filter</button>
    <a class="btn sm o" href="logs.php">Clear</a>
  </form>
  <div class="tbl-wrap"><table class="tbl fixm">
    <tr><th>Time</th><th>User</th><th>Action</th><th class="hm">Details</th><th class="hm">IP</th></tr>
    <?php foreach ($logs as $log): ?>
    <tr>
      <td class="nw"><?= e(date('d M Y, H:i', strtotime((string) $log['created_at']))) ?></td>
      <td><?= e($log['user_name']) ?></td>
      <td><span class="tag tu"><?= e(str_replace('_', ' ', $log['action'])) ?></span><small class="dt-m"><?= e((string) ($log['details'] ?? '')) ?></small></td>
      <td class="hm"><?= e((string) ($log['details'] ?? '')) ?></td>
      <td class="hm"><?= e((string) $log['ip_address']) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?><tr><td colspan="5" class="k" style="padding:18px 12px">No logs found.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($total_pages > 1): ?>
  <div class="chips" style="margin-top:12px;justify-content:center">
    <?php if ($page > 1): ?><a class="chip" href="<?= e($link(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
    <span class="chip">Page <?= $page ?> of <?= $total_pages ?></span>
    <?php if ($page < $total_pages): ?><a class="chip" href="<?= e($link(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_page_end();
