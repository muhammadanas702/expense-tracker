<?php
require_once __DIR__ . '/auth.php';
admin_need('add');

$error = '';
$name = $email = '';
$role = 'user';
$created = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = (string) ($_POST['name'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $role = $admin['role'] === 'super' && isset(ADMIN_ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'user'; // only a Super Admin can create staff
    $clean = clean_text($name, 100);
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($clean === null) {
        $error = 'Please enter a name (up to 100 characters).';
    } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $dup = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $pw = admin_temp_password();
            $conn->prepare('INSERT INTO users (name, email, password) VALUES (?,?,?)')->execute([$clean, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $newId = (int) $conn->lastInsertId();
            if ($role !== 'user') {
                admin_set_role($conn, $newId, $role);
            }
            admin_audit($conn, $admin['id'], 'add_user', 'Added ' . $clean . ' (ID ' . $newId . ') as ' . admin_role_label($role));
            require_once __DIR__ . '/../config/mail.php';
            $created = ['id' => $newId, 'email' => $email, 'pw' => $pw, 'mailed' => sendTempPasswordMail($email, $clean, $pw)];
        }
    }
}

admin_page_start(['title' => 'Add user', 'sub' => 'Create an account by hand', 'active' => 'users']);
?>
<?php if ($created): ?>
<div class="g card" style="border-color:rgba(245,158,11,.5)">
  <div class="t">Account created</div>
  <p class="k" style="margin-bottom:8px"><?= !empty($created['mailed']) ? 'The details were also emailed to the user. ' : 'The email could not be sent, so give these details to the user privately. ' ?>The password is shown only once; they should change it in Profile.</p>
  <div class="row"><span class="k">Email</span><b><?= e($created['email']) ?></b></div>
  <div class="row"><span class="k">Temporary password</span><b><code style="font-size:1.1rem;user-select:all"><?= e($created['pw']) ?></code></b></div>
  <p style="margin-top:12px;display:flex;gap:8px"><a class="btn sm" href="view-user.php?id=<?= (int) $created['id'] ?>">Open profile</a><a class="btn sm o" href="user-add.php">Add another</a></p>
</div>
<?php else: ?>
<div class="g card" style="max-width:560px">
  <?= $error !== '' ? '<div class="msg err">' . e($error) . '</div>' : '' ?>
  <form method="post">
    <?= csrf_field() ?>
    <label class="k" for="nm">Name</label><input class="inp" id="nm" name="name" value="<?= e($name) ?>" maxlength="100" required style="margin:4px 0 12px">
    <label class="k" for="em">Email</label><input class="inp" id="em" type="email" name="email" value="<?= e($email) ?>" maxlength="190" required style="margin:4px 0 12px">
    <?php if ($admin['role'] === 'super'): ?>
    <label class="k" for="rl">Role</label>
    <select class="inp" id="rl" name="role" style="margin:4px 0 12px"><?php foreach (ADMIN_ROLES as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $role ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php endif; ?>
    <p class="k" style="margin-bottom:12px">A strong temporary password is generated and shown once after saving.</p>
    <button class="btn" type="submit">Create account</button>
  </form>
</div>
<?php endif; ?>
<p style="margin-top:14px"><a class="btn o sm" href="users.php"><?= ef_icon('bk') ?> All users</a></p>
<?php admin_page_end();
