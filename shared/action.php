<?php
// One POST endpoint for every group action. Each action re-checks membership/ownership on the server.
require_once __DIR__ . '/../modules/shared/boot.php';
require_post_csrf();

$do = (string) ($_POST['do'] ?? '');
$gid = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

if ($do === 'create') {
    $key = rl_key((string) $user_id);
    if (rl_blocked($conn, 'shared_create', $key, 10, 3600)) {
        $_SESSION['error'] = 'You created many groups just now. Please try again later.';
        shared_back('shared/groups.php');
    }
    $name = clean_text($_POST['name'] ?? '', 60);
    if ($name === null) {
        $_SESSION['error'] = 'Please enter a group name (up to 60 characters).';
        shared_back('shared/groups.php');
    }
    $err = null;
    $id = shared_create_group($conn, $user_id, $name, (string) ($_POST['icon'] ?? ''), $err);
    if ($id === null) {
        $_SESSION['error'] = $err ?? 'Could not create the group.';
        shared_back('shared/groups.php');
    }
    rl_hit($conn, 'shared_create', $key);
    logAction($conn, $user_id, 'shared_create_group', "Group ID: $id");
    $_SESSION['success'] = 'Group created. Invite your friends with the link below.';
    shared_back('shared/group.php?id=' . $id);
}

if ($do === 'join') {
    $code = (string) ($_POST['code'] ?? '');
    $key = rl_key((string) $user_id);
    if (rl_blocked($conn, 'shared_join', $key, 20, 3600)) {
        $_SESSION['error'] = 'Too many attempts. Please try again later.';
        shared_back('shared/groups.php');
    }
    $g = null;
    if (preg_match('/^[0-9a-f]{32}$/', $code)) {
        $q = $conn->prepare('SELECT id, name FROM shared_groups WHERE invite_code = ?');
        $q->execute([$code]);
        $g = $q->fetch(PDO::FETCH_ASSOC);
    }
    if (!$g) {
        rl_hit($conn, 'shared_join', $key);
        $_SESSION['error'] = 'This invite link is not valid any more.';
        shared_back('shared/groups.php');
    }
    $res = shared_add_member($conn, (int) $g['id'], $user_id);
    if ($res === 'full') {
        $_SESSION['error'] = 'This group is full, or you are in the maximum number of groups.';
        shared_back('shared/groups.php');
    }
    if ($res === 'joined') {
        logAction($conn, $user_id, 'shared_join_group', 'Group ID: ' . (int) $g['id']);
        $_SESSION['success'] = 'You joined ' . $g['name'] . '.';
    }
    shared_back('shared/group.php?id=' . (int) $g['id']);
}

// Everything below needs a group the signed-in user belongs to.
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    http_response_code(404);
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$isOwner = $group['my_role'] === 'owner';
$back = 'shared/group.php?id=' . $gid;

switch ($do) {
    case 'rename':
        if (!$isOwner) { $_SESSION['error'] = 'Only the group owner can do that.'; break; }
        $name = clean_text($_POST['name'] ?? '', 60);
        if ($name === null) { $_SESSION['error'] = 'Please enter a group name (up to 60 characters).'; break; }
        $conn->prepare('UPDATE shared_groups SET name = ?, icon = ? WHERE id = ?')->execute([$name, shared_icon_key($_POST['icon'] ?? ''), $gid]);
        logAction($conn, $user_id, 'shared_rename_group', "Group ID: $gid");
        $_SESSION['success'] = 'Group updated.';
        break;

    case 'regen':
        if (!$isOwner) { $_SESSION['error'] = 'Only the group owner can do that.'; break; }
        $conn->prepare('UPDATE shared_groups SET invite_code = ? WHERE id = ?')->execute([shared_new_code(), $gid]);
        logAction($conn, $user_id, 'shared_new_invite', "Group ID: $gid");
        $_SESSION['success'] = 'New invite link created. The old link no longer works.';
        break;

    case 'remove':
        if (!$isOwner) { $_SESSION['error'] = 'Only the group owner can do that.'; break; }
        $uid = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($uid === $user_id) { $_SESSION['error'] = 'You are the owner. Delete the group instead.'; break; }
        shared_remove_member($conn, $gid, $uid);
        logAction($conn, $user_id, 'shared_remove_member', "Group ID: $gid, user ID: $uid");
        $_SESSION['success'] = 'Member removed.';
        break;

    case 'leave':
        if ($isOwner) { $_SESSION['error'] = 'The owner cannot leave. Delete the group instead.'; break; }
        shared_remove_member($conn, $gid, $user_id);
        logAction($conn, $user_id, 'shared_leave_group', "Group ID: $gid");
        $_SESSION['success'] = 'You left the group.';
        shared_back('shared/groups.php');

    case 'delete_expense':
        $xid = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $x = $xid ? shared_expense_get($conn, $xid, $gid) : null;
        if (!$x) { $_SESSION['error'] = 'Expense not found.'; break; }
        if (!shared_can_edit($x, $group, $user_id)) { $_SESSION['error'] = 'Only the person who added it, or the group owner, can delete this expense.'; break; }
        shared_expense_delete($conn, $x);
        logAction($conn, $user_id, 'shared_delete_expense', "Group ID: $gid, expense ID: $xid");
        $_SESSION['success'] = 'Expense deleted.';
        break;

    case 'delete':
        if (!$isOwner) { $_SESSION['error'] = 'Only the group owner can do that.'; break; }
        shared_delete_group($conn, $gid);
        logAction($conn, $user_id, 'shared_delete_group', "Group ID: $gid");
        $_SESSION['success'] = 'Group deleted.';
        shared_back('shared/groups.php');

    default:
        $_SESSION['error'] = 'Unknown action.';
}
shared_back($back);
