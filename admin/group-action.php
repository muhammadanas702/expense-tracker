<?php
/** Staff side of Groups: delete a group. Needs the "grp" permission. */
require_once __DIR__ . '/auth.php';
admin_need('grp');
require_post_csrf();
require_once __DIR__ . '/../modules/groupsadmin/lib.php';

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$g = $id ? groupsadmin_get($conn, $id) : null;
if (($_POST['do'] ?? '') === 'delete' && $g) {
    try {
        groupsadmin_delete($conn, $id);
        admin_audit($conn, $admin['id'], 'group_delete', 'Deleted group ID ' . $id . ' ("' . $g['name'] . '")');
        $_SESSION['success'] = 'Group deleted.';
    } catch (Throwable $e) {
        error_log('ExpenseFlow admin group delete failed: ' . $e->getMessage());
        $_SESSION['error'] = 'Could not delete the group. Nothing was changed.';
    }
} else {
    $_SESSION['error'] = 'Group not found.';
}
header('Location: groups.php');
exit();
