<?php
/** One POST endpoint for account actions: block, unblock, reset password, change role, edit details. */
require_once __DIR__ . '/auth.php';
require_post_csrf();

$do = (string) ($_POST['do'] ?? '');
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$back = 'view-user.php?id=' . $id;
$ret = ($_POST['ret'] ?? '') === 'list' ? 'users.php' : $back;

$stmt = $conn->prepare('SELECT id, name, email, role, blocked FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
$fail = function (string $msg) use ($ret) {
    $_SESSION['error'] = $msg;
    header('Location: ' . $ret);
    exit();
};
if (!$target) {
    $fail('User not found.');
}
if (!admin_can_manage($admin, $target)) {
    $fail($admin['id'] === (int) $target['id'] ? 'You cannot do this to your own account.' : 'Your role cannot manage this account.');
}
$who = $target['name'] . ' (ID ' . $id . ')';

if ($do === 'block' || $do === 'unblock') {
    admin_need('block');
    $flag = $do === 'block' ? 1 : 0;
    $conn->prepare('UPDATE users SET blocked = ? WHERE id = ?')->execute([$flag, $id]);
    admin_audit($conn, $admin['id'], $do . '_user', ($flag ? 'Blocked ' : 'Unblocked ') . $who);
    $_SESSION['success'] = $flag ? 'Account blocked. The user is signed out on the next request.' : 'Account unblocked.';
} elseif ($do === 'reset') {
    admin_need('reset');
    $pw = admin_temp_password();
    $conn->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    admin_audit($conn, $admin['id'], 'reset_password', 'Set a temporary password for ' . $who);
    $_SESSION['admin_secret'] = ['uid' => $id, 'pw' => $pw];
    $_SESSION['success'] = 'A temporary password was set. Copy it now: it is shown only once.';
} elseif ($do === 'role') {
    admin_need('SA');
    $role = (string) ($_POST['role'] ?? '');
    if (!isset(ADMIN_ROLES[$role])) {
        $fail('Unknown role.');
    }
    if ($target['role'] === 'super' && $role !== 'super' && admin_super_count($conn) <= 1) {
        $fail('This is the only Super Admin. Make another user a Super Admin first.');
    }
    if ($role !== $target['role']) {
        admin_set_role($conn, $id, $role);
        admin_audit($conn, $admin['id'], 'set_role', $who . ': ' . admin_role_label($target['role']) . ' to ' . admin_role_label($role));
    }
    $_SESSION['success'] = 'Role updated.';
} elseif ($do === 'edit') {
    admin_need('SA');
    $name = clean_text($_POST['name'] ?? '', 100);
    $email = trim((string) ($_POST['email'] ?? ''));
    if ($name === null) {
        $fail('Please enter a name (up to 100 characters).');
    }
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('Please enter a valid email address.');
    }
    $dup = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $dup->execute([$email, $id]);
    if ($dup->fetch()) {
        $fail('Another account already uses that email address.');
    }
    $conn->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
    admin_audit($conn, $admin['id'], 'edit_user', 'Edited name/email of ' . $who);
    $_SESSION['success'] = 'Details saved.';
} else {
    $fail('Unknown action.');
}
header('Location: ' . $ret);
exit();
