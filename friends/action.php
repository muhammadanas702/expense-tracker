<?php
// POST endpoint for friend requests: add by email, accept, decline, cancel, remove.
require_once __DIR__ . '/../modules/friends/boot.php';
require_post_csrf();
$do = (string) ($_POST['do'] ?? '');
if ($do === 'add') {
    $r = friends_request($conn, $user_id, (string) ($_POST['email'] ?? ''));
    $_SESSION[$r['ok'] ? 'success' : 'error'] = $r['msg'];
    logAction($conn, $user_id, 'friend_request', 'Friend request sent');
} elseif (in_array($do, ['accept', 'decline', 'cancel', 'remove'], true)) {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($id && friends_respond($conn, $user_id, $id, $do)) {
        $_SESSION['success'] = ['accept' => 'Friend added.', 'decline' => 'Request declined.', 'cancel' => 'Request cancelled.', 'remove' => 'Friend removed.'][$do];
    } else {
        $_SESSION['error'] = 'That request is no longer available.';
    }
}
shared_back('friends/index.php');
