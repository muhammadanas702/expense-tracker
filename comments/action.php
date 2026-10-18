<?php
// Comments: add or delete. Membership of the group is re-checked on the server for every request.
require_once __DIR__ . '/../modules/shared/boot.php';
if (!function_exists('comments_add')) {
    http_response_code(404);
    exit('Comments are switched off.');
}
require_post_csrf();

$do = (string) ($_POST['do'] ?? '');
$gid = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$back = 'shared/group.php?id=' . $gid;
$xid = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$expense = $xid ? shared_expense_get($conn, $xid, $gid) : null;

if ($do === 'add') {
    $key = rl_key((string) $user_id);
    if (!$expense) {
        $_SESSION['error'] = 'Expense not found.';
    } elseif (comments_is_duplicate($conn, $xid, $user_id, (string) ($_POST['body'] ?? ''))) {
        // the same text a moment ago: a double tap, nothing to add
    } elseif (rl_blocked($conn, 'comment_add', $key, 30, 600)) {
        $_SESSION['error'] = 'You are commenting very fast. Please wait a few minutes.';
    } else {
        $err = null;
        $id = comments_add($conn, $gid, $xid, $user_id, (string) ($_POST['body'] ?? ''), $err);
        if ($id === null) {
            $_SESSION['error'] = $err ?? 'Could not save the comment.';
        } else {
            rl_hit($conn, 'comment_add', $key);
            $involved = array_merge(array_keys(shared_splits_of($conn, $xid)), [(int) $expense['paid_by'], (int) $expense['created_by']]);
            shared_emit($conn, $gid, $user_id, 'comment', ['title' => $expense['title']], $involved);
            logAction($conn, $user_id, 'comment_add', "Group ID: $gid, expense ID: $xid");
            $_SESSION['success'] = 'Comment added.';
        }
    }
} elseif ($do === 'delete') {
    $cid = filter_var($_POST['comment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $c = $cid ? comments_get($conn, $cid, $gid) : null;
    if (!$c) {
        $_SESSION['error'] = 'Comment not found.';
    } elseif ((int) $c['user_id'] !== $user_id && $group['my_role'] !== 'owner') {
        $_SESSION['error'] = 'You can only delete your own comments.';
    } else {
        comments_delete($conn, $cid);
        logAction($conn, $user_id, 'comment_delete', "Group ID: $gid, comment ID: $cid");
        $_SESSION['success'] = 'Comment deleted.';
    }
} else {
    $_SESSION['error'] = 'Unknown action.';
}
shared_back($back . ($xid ? '#x' . $xid : ''));
