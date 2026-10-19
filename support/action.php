<?php
// Support (user side): create a request, reply, or close it. The ticket must belong to the logged-in user.
require_once __DIR__ . '/../modules/support/boot.php';
require_post_csrf();

$do = (string) ($_POST['do'] ?? '');
$key = rl_key((string) $user_id);

if ($do === 'create') {
    $subject = (string) ($_POST['subject'] ?? '');
    $category = (string) ($_POST['category'] ?? 'other');
    $body = (string) ($_POST['body'] ?? '');
    if (rl_blocked($conn, 'support_create', $key, 5, 3600)) {
        $_SESSION['error'] = 'You sent many requests in a short time. Please wait a little.';
        $_SESSION['support_old'] = compact('subject', 'category', 'body');
        support_back('support/index.php');
    }
    $err = null;
    $id = support_create($conn, $user_id, $subject, $category, $body, $err);
    if ($id === null) {
        $_SESSION['error'] = $err ?? 'Could not send your request.';
        $_SESSION['support_old'] = compact('subject', 'category', 'body');
        support_back('support/index.php');
    }
    rl_hit($conn, 'support_create', $key);
    logAction($conn, $user_id, 'support_create', "Support request ID: $id");
    $_SESSION['success'] = 'Request sent. We will answer here.';
    support_back('support/ticket.php?id=' . $id);
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$ticket = $id ? support_ticket_for_user($conn, $id, $user_id) : null;
if (!$ticket) {
    $_SESSION['error'] = 'Request not found.';
    support_back('support/index.php');
}

if ($do === 'reply') {
    if (rl_blocked($conn, 'support_reply', $key, 20, 3600)) {
        $_SESSION['error'] = 'You are writing very fast. Please wait a few minutes.';
    } else {
        $err = null;
        if (support_reply($conn, $ticket, $user_id, false, (string) ($_POST['body'] ?? ''), $err)) {
            rl_hit($conn, 'support_reply', $key);
            logAction($conn, $user_id, 'support_reply', "Support request ID: $id");
            $_SESSION['success'] = 'Reply sent.';
        } else {
            $_SESSION['error'] = $err ?? 'Could not send the reply.';
        }
    }
} elseif ($do === 'close') {
    support_set_status($conn, $id, 'closed');
    logAction($conn, $user_id, 'support_close', "Support request ID: $id");
    $_SESSION['success'] = 'Marked as solved. Thank you!';
} else {
    $_SESSION['error'] = 'Unknown action.';
}
support_back('support/ticket.php?id=' . $id);
