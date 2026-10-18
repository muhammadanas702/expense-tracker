<?php
require_once __DIR__ . '/auth.php';
admin_need('view');

$filter = in_array($_GET['f'] ?? '', ['active', 'blocked', 'staff'], true) ? $_GET['f'] : 'all';
$sortKey = in_array($_GET['s'] ?? '', ['name', 'login'], true) ? $_GET['s'] : 'new';
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 25;

$where = [];
$params = [];
if ($filter === 'active') { $where[] = 'u.blocked = 0'; }
if ($filter === 'blocked') { $where[] = 'u.blocked = 1'; }
if ($filter === 'staff') { $where[] = "u.role <> 'user'"; }
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    array_push($params, $like, $like);
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$order = ['new' => 'u.id DESC', 'name' => 'u.name ASC', 'login' => 'last_login IS NULL, last_login DESC'][$sortKey];

$cnt = $conn->prepare("SELECT COUNT(*) FROM users u $w");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.role, u.blocked, u.created_at,
    (SELECT MAX(l.created_at) FROM user_logs l WHERE l.user_id = u.id AND l.action = 'login') AS last_login
    FROM users u $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canBlock = admin_can($conn, $admin['role'], 'block');
$canAdd = admin_can($conn, $admin['role'], 'add');
$canExport = admin_can($conn, $admin['role'], 'export');
$csrf = csrf_token();
$url = fn(array $o = []) => 'users.php?' . http_build_query(array_filter(array_merge(['f' => $filter === 'all' ? '' : $filter, 's' => $sortKey === 'new' ? '' : $sortKey, 'q' => $q, 'p' => $page > 1 ? $page : ''], $o), fn($v) => $v !== '' && $v !== null));
$ago = function (?string $t) {
    if (!$t) { return 'Never'; }
    $d = time() - strtotime($t);
    return $d < 3600 ? max(1, (int) ($d / 60)) . ' min ago' : ($d < 86400 ? (int) ($d / 3600) . ' h ago' : ($d < 2592000 ? (int) ($d / 86400) . ' d ago' : date('d M Y', strtotime($t))));
};

admin_page_start(['title' => 'Users', 'sub' => $total . ' account' . ($total === 1 ? '' : 's'), 'active' => 'users']);
?>
<div class="g card">
  <div class="toolbar">
    <form method="get" style="display:flex;gap:10px;flex:1;min-width:200px;flex-wrap:wrap">
      <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif; ?>
      <?php if ($sortKey !== 'new'): ?><input type="hidden" name="s" value="<?= e($sortKey) ?>"><?php endif; ?>
      <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or email" maxlength="60">
      <button class="btn sm" type="submit">Search</button>
    </form>
    <?php if ($canAdd): ?><a class="btn sm" href="user-add.php"><?= ef_icon('pl') ?> Add user</a><?php endif; ?>
    <?php if ($canExport): ?><a class="btn sm o" href="user-export.php"><?= ef_icon('dl') ?> Export CSV</a><?php endif; ?>
  </div>
  <div class="chips" style="margin-bottom:12px">
    <?php foreach (['all' => 'All', 'active' => 'Active', 'blocked' => 'Blocked', 'staff' => 'Staff'] as $k => $l): ?>
      <a class="chip<?= $filter === $k ? ' on' : '' ?>" href="<?= e($url(['f' => $k === 'all' ? '' : $k, 'p' => ''])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <span style="flex:1"></span>
    <?php foreach (['new' => 'Newest', 'name' => 'Name', 'login' => 'Last login'] as $k => $l): ?>
      <a class="chip<?= $sortKey === $k ? ' on' : '' ?>" href="<?= e($url(['s' => $k === 'new' ? '' : $k, 'p' => ''])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>User</th><th class="hm">Joined</th><th class="hm">Last login</th><th>Role</th><th>Status</th><th></th></tr>
    <?php foreach ($users as $u): $m = admin_can_manage($admin, $u); ?>
    <tr>
      <td><a class="who" href="view-user.php?id=<?= (int) $u['id'] ?>"><i class="av" style="background:#1d4ed8"><?= e(ef_initials($u['name'])) ?></i><span><b><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></span></a></td>
      <td class="hm"><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></td>
      <td class="hm"><?= e($ago($u['last_login'])) ?></td>
      <td><span class="tag <?= $u['role'] === 'super' ? 'rs' : ($u['role'] === 'user' ? 'tu' : 'tb2') ?>"><?= e(admin_role_label($u['role'])) ?></span></td>
      <td><span class="tag <?= $u['blocked'] ? 'tx' : 'tg' ?>"><?= $u['blocked'] ? 'Blocked' : 'Active' ?></span></td>
      <td><div class="acts-r">
        <a class="btn sm o" href="view-user.php?id=<?= (int) $u['id'] ?>">Open</a>
        <?php if ($canBlock && $m): ?>
        <form method="post" action="user-action.php" onsubmit="return confirm('<?= $u['blocked'] ? 'Unblock' : 'Block' ?> this user?')">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="ret" value="list"><input type="hidden" name="do" value="<?= $u['blocked'] ? 'unblock' : 'block' ?>">
          <button class="btn sm <?= $u['blocked'] ? 'i2' : 'e2' ?>" type="submit"><?= $u['blocked'] ? 'Unblock' : 'Block' ?></button>
        </form>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?><tr><td colspan="6" class="k" style="padding:18px 12px">No users match.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="chips" style="margin-top:12px;justify-content:center">
    <?php if ($page > 1): ?><a class="chip" href="<?= e($url(['p' => $page - 1])) ?>">← Previous</a><?php endif; ?>
    <span class="chip">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="chip" href="<?= e($url(['p' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_page_end();
