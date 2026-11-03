<?php
// POST endpoint for friend requests: add by email, accept, decline, cancel, remove.
require_once __DIR__ . '/../modules/friends/boot.php';
require_post_csrf();
$do = (string) ($_POST['do'] ?? '');
if ($do === 'add') {
    $r = friends_request($conn, $user_id, (string) ($_POST['email'] ?? ''));
    $_SESSION[$r['ok'] ? 'success' : 'error'] = $r['msg'];
    logAction($conn, $user_id, 'friend_request', 'Friend request sent');
} elseif ($do === 'nudge') {
    // "Remind me to settle up" for the OTHER side: tell a friend who owes you money, at most once every 3 days per friend.
    $fid = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $f = $fid ? friends_get($conn, $user_id, $fid) : null;
    $cur = $f ? array_key_first($f['owed']) : null;
    if (!$f || $cur === null) {
        $_SESSION['error'] = 'This friend does not owe you anything right now.';
    } elseif (rl_blocked($conn, 'settle_nudge', rl_key((string) $user_id, (string) $fid), 1, 3 * 86400)) {
        $_SESSION['error'] = 'You already reminded ' . $f['name'] . ' recently. You can remind again in a few days.';
    } else {
        rl_hit($conn, 'settle_nudge', rl_key((string) $user_id, (string) $fid));
        if (function_exists('activity_record')) {
            activity_record($conn, 0, $user_id, 'settle_nudge', ['amount_minor' => (int) $f['owed'][$cur], 'currency' => $cur, 'target_id' => $fid], [$fid]);
        }
        $_SESSION['success'] = 'Reminder sent to ' . $f['name'] . '.';
    }
    shared_back('friends/view.php?id=' . (int) $fid);
} elseif (in_array($do, ['accept', 'decline', 'cancel', 'remove'], true)) {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    if ($id && friends_respond($conn, $user_id, $id, $do)) {
        $_SESSION['success'] = ['accept' => 'Friend added.', 'decline' => 'Request declined.', 'cancel' => 'Request cancelled.', 'remove' => 'Friend removed.'][$do];
    } else {
        $_SESSION['error'] = 'That request is no longer available.';
    }
}
shared_back('friends/index.php');
