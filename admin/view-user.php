<?php
require_once __DIR__ . '/auth.php';
admin_need('view');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$stmt = $conn->prepare('SELECT id, name, email, role, blocked, created_at FROM users WHERE id = ?');
$stmt->execute([$id]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) {
    http_response_code(404);
    $_SESSION['error'] = 'User not found.';
    header('Location: users.php');
    exit();
}
$count = function (string $sql) use ($conn, $id) { try { $s = $conn->prepare($sql); $s->execute([$id]); return (int) $s->fetchColumn(); } catch (Throwable $e) { return 0; } };
$stats = [
    'Income entries' => $count('SELECT COUNT(*) FROM income WHERE user_id = ?'),
    'Expense entries' => $count('SELECT COUNT(*) FROM expenses WHERE user_id = ?'),
    'Shared groups' => $count('SELECT COUNT(*) FROM shared_members WHERE user_id = ?'),
];
$last = $conn->prepare("SELECT MAX(created_at) FROM user_logs WHERE user_id = ? AND action = 'login'");
$last->execute([$id]);
$lastLogin = $last->fetchColumn();
$logs = $conn->prepare('SELECT action, details, created_at FROM user_logs WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$logs->execute([$id]);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);
if (($u['role'] ?? '') === 'super' && $admin['role'] !== 'super') {
    $logs = [];          // a Super Admin's activity is for Super Admins only
    $lastLogin = null;
}

$manage = admin_can_manage($admin, $u);
$can = fn($p) => $manage && admin_can($conn, $admin['role'], $p);
$secret = $_SESSION['admin_secret'] ?? null;
unset($_SESSION['admin_secret']);
if (!is_array($secret) || (int) ($secret['uid'] ?? 0) !== $id) {
    $secret = null;
}
$csrf = csrf_token();
$form = fn(array $f, string $btn, string $cls = 'o', string $confirm = '') => '<form method="post" action="user-action.php" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '><input type="hidden" name="csrf_token" value="' . e($csrf) . '"><input type="hidden" name="id" value="' . $id . '">' . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($f), $f)) . '<button class="btn sm ' . $cls . '" type="submit">' . $btn . '</button></form>';

admin_page_start(['title' => $u['name'], 'sub' => 'User profile', 'active' => 'users']);
?>
<?php if ($secret): ?>
<div class="g card" style="margin-bottom:14px;border-color:rgba(245,158,11,.5)">
  <div class="t">Temporary password (shown once)</div>
  <p class="k" style="margin-bottom:8px">Give this to the user in person or by a private message. They should change it in Profile after logging in.</p>
  <code style="font-size:1.2rem;font-weight:800;letter-spacing:1px;user-select:all"><?= e($secret['pw']) ?></code>
</div>
<?php endif; ?>

<div class="G2">
  <div class="g card">
    <div class="who" style="margin-bottom:14px"><i class="av" style="background:#1d4ed8;width:52px;height:52px;font-size:1rem"><?= e(ef_initials($u['name'])) ?></i>
      <span><b style="font-size:1.1rem"><?= e($u['name']) ?></b><small><?= e($u['email']) ?></small></span></div>
    <div class="row"><span class="k">Role</span><b><span class="tag <?= $u['role'] === 'super' ? 'rs' : ($u['role'] === 'user' ? 'tu' : 'tb2') ?>"><?= e(admin_role_label($u['role'])) ?></span></b></div>
    <div class="row"><span class="k">Status</span><b><span class="tag <?= $u['blocked'] ? 'tx' : 'tg' ?>"><?= $u['blocked'] ? 'Blocked' : 'Active' ?></span></b></div>
    <div class="row"><span class="k">Joined</span><b><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></b></div>
    <div class="row"><span class="k">Last login</span><b><?= $lastLogin ? e(date('d M Y, H:i', strtotime((string) $lastLogin))) : 'Never' ?></b></div>
    <?php foreach ($stats as $k => $v): ?><div class="row"><span class="k"><?= e($k) ?></span><b><?= $v ?></b></div><?php endforeach; ?>
  </div>

  <div class="g card">
    <div class="t">Actions</div>
    <?php if (!$manage): ?>
      <p class="k"><?= $admin['id'] === (int) $u['id'] ? 'This is your own account. Use Profile to change it.' : 'Your role cannot manage this account.' ?></p>
    <?php else: ?>
    <div class="acts-r" style="justify-content:flex-start;margin-bottom:12px">
      <?php if ($can('block')): ?><?= $u['blocked'] ? $form(['do' => 'unblock'], 'Unblock', 'i2') : $form(['do' => 'block'], 'Block user', 'e2', 'Block this user? They are signed out and cannot log in.') ?><?php endif; ?>
      <?php if ($can('reset')): ?><?= $form(['do' => 'reset'], 'Set temporary password', 'o', 'Replace this user\'s password with a temporary one?') ?><?php endif; ?>
      <?php if ($can('del')): ?>
        <a class="btn sm e2" href="delete-user.php" data-post data-csrf="<?= e($csrf) ?>" data-fields="<?= e(json_encode(['id' => $id])) ?>" onclick="return confirm('Delete this user and all their data? This cannot be undone.')">Delete user</a>
      <?php endif; ?>
    </div>
    <?php if ($can('SA')): ?>
    <form method="post" action="user-action.php" style="margin-top:6px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="role">
      <label class="k" for="role">Role</label>
      <div style="display:flex;gap:8px;margin:4px 0 14px">
        <select class="inp" id="role" name="role"><?php foreach (ADMIN_ROLES as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $u['role'] ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn sm" type="submit">Save</button>
      </div>
    </form>
    <form method="post" action="user-action.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="edit">
      <label class="k" for="nm">Name</label><input class="inp" id="nm" name="name" value="<?= e($u['name']) ?>" maxlength="100" required style="margin:4px 0 10px">
      <label class="k" for="em">Email</label><input class="inp" id="em" type="email" name="email" value="<?= e($u['email']) ?>" maxlength="190" required style="margin:4px 0 10px">
      <button class="btn sm" type="submit">Save details</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="g card" style="margin-top:14px">
  <div class="t">Recent activity of this user</div>
  <?php if ($logs): foreach ($logs as $l): ?>
  <div class="row"><span><?= e((string) $l['details'] ?: $l['action']) ?><small><?= e($l['action']) ?> · <?= e(date('d M, H:i', strtotime((string) $l['created_at']))) ?></small></span></div>
  <?php endforeach; else: ?><p class="k">No activity yet.</p><?php endif; ?>
</div>
<p style="margin-top:14px"><a class="btn o sm" href="users.php"><?= ef_icon('bk') ?> All users</a></p>
<?php admin_page_end();
