<?php
require_once __DIR__ . '/auth.php';
admin_need('view');

$q = trim((string) ($_GET['q'] ?? ''));
$users = [];
$sql = "SELECT id, name, email, role, created_at FROM users";
$params = [];
if ($q !== '') {
    $sql .= " WHERE name LIKE ? OR email LIKE ?";
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $params = [$like, $like];
}
$stmt = $conn->prepare($sql . " ORDER BY id DESC LIMIT 200");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$canDelete = admin_can($conn, $admin['role'], 'del');
$canEdit = $admin['role'] === 'super';

admin_page_start(['title' => 'Users', 'sub' => count($users) . ' shown', 'active' => 'users']);
?>
<div class="g card">
  <form method="get" class="toolbar">
    <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or email" maxlength="60">
    <button class="btn sm" type="submit">Search</button>
  </form>
  <div class="tbl-wrap"><table class="tbl">
    <tr><th>User</th><th class="hm">Joined</th><th>Role</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><div class="who"><i class="av" style="background:#1d4ed8"><?= e(ef_initials($u['name'])) ?></i><span><b><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></span></div></td>
      <td class="hm"><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></td>
      <td><span class="tag <?= $u['role'] === 'super' ? 'rs' : ($u['role'] === 'user' ? 'tu' : 'tb2') ?>"><?= e(admin_role_label($u['role'])) ?></span></td>
      <td><div class="acts-r">
        <a class="btn sm o" href="view-user.php?id=<?= (int) $u['id'] ?>">View</a>
        <?php if ($canEdit): ?><a class="btn sm o" href="edit-user.php?id=<?= (int) $u['id'] ?>">Edit</a><?php endif; ?>
        <?php if ($canDelete && (int) $u['id'] !== $admin['id']): ?>
        <a class="btn sm e2" href="delete-user.php" data-post data-csrf="<?= e(csrf_token()) ?>" data-fields="<?= e(json_encode(['id' => (int) $u['id']])) ?>" onclick="return confirm('Delete this user and all their data?')">Delete</a>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php admin_page_end();
