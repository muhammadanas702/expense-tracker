<?php
require_once __DIR__ . '/auth.php';
admin_need('SA');

$matrix = admin_matrix($conn, true);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $changes = [];
        foreach (['admin', 'support'] as $role) {
            foreach (ADMIN_PERMS as $perm => $label) {
                $new = !empty($_POST['p'][$role][$perm]);
                if ($new !== !empty($matrix[$role][$perm])) {
                    admin_set_perm($conn, $role, $perm, $new);
                    $changes[] = admin_role_label($role) . ': ' . $label . ($new ? ' on' : ' off');
                }
            }
        }
        if ($changes) {
            admin_audit($conn, $admin['id'], 'set_perms', mb_substr(implode('; ', $changes), 0, 480));
            $_SESSION['success'] = count($changes) . ' permission' . (count($changes) === 1 ? '' : 's') . ' changed.';
        } else {
            $_SESSION['success'] = 'Nothing changed.';
        }
        header('Location: roles.php');
        exit();
    }
}

$staff = $conn->query("SELECT id, name, email, role FROM users WHERE role <> 'user' ORDER BY FIELD(role,'super','admin','support'), name")->fetchAll(PDO::FETCH_ASSOC);
admin_page_start(['title' => 'Roles & permissions', 'sub' => 'What each staff role may do', 'active' => 'roles']);
?>
<?= $error !== '' ? '<div class="msg err">' . e($error) . '</div>' : '' ?>
<div class="G2">
  <form method="post" class="g card">
    <?= csrf_field() ?>
    <div class="t">Permissions</div>
    <p class="k" style="margin-bottom:10px">Super Admin can always do everything. Choose what Admin and Support may do. Changes apply at once and are written to the audit log.</p>
    <div class="tbl-wrap"><table class="tbl">
      <tr><th>Permission</th><th style="text-align:center">Admin</th><th style="text-align:center">Support</th></tr>
      <?php foreach (ADMIN_PERMS as $perm => $label): ?>
      <tr>
        <td><?= e($label) ?></td>
        <?php foreach (['admin', 'support'] as $role): ?>
        <td style="text-align:center"><input type="checkbox" name="p[<?= e($role) ?>][<?= e($perm) ?>]" value="1" aria-label="<?= e($label . ' for ' . admin_role_label($role)) ?>"<?= !empty($matrix[$role][$perm]) ? ' checked' : '' ?> style="width:18px;height:18px;accent-color:var(--a1)"></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p style="margin-top:14px"><button class="btn" type="submit">Save permissions</button></p>
  </form>

  <div class="g card">
    <div class="t">Staff (<?= count($staff) ?>)</div>
    <?php foreach ($staff as $s): ?>
    <a class="row" href="view-user.php?id=<?= (int) $s['id'] ?>" style="text-decoration:none;color:inherit">
      <i class="av" style="background:#1d4ed8"><?= e(ef_initials($s['name'])) ?></i>
      <span><?= e($s['name']) ?><small><?= e($s['email']) ?></small></span>
      <span class="tag <?= $s['role'] === 'super' ? 'rs' : 'tb2' ?>"><?= e(admin_role_label($s['role'])) ?></span>
    </a>
    <?php endforeach; ?>
    <p class="k" style="margin-top:10px">To change someone's role, open their profile.</p>
  </div>
</div>
<?php admin_page_end();
