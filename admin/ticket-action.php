<?php
/** Staff side of Support: reply, close, reopen. Needs the "support" permission. */
require_once __DIR__ . '/auth.php';
admin_need('support');
require_post_csrf();
require_once __DIR__ . '/../modules/support/lib.php';

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$ticket = $id ? support_ticket_get($conn, $id) : null;
if (!$ticket) {
    $_SESSION['error'] = 'Request not found.';
    header('Location: support.php');
    exit();
}
$do = (string) ($_POST['do'] ?? '');

if ($do === 'reply') {
    $err = null;
    if (support_reply($conn, $ticket, $admin['id'], true, (string) ($_POST['body'] ?? ''), $err)) {
        admin_audit($conn, $admin['id'], 'ticket_reply', 'Replied to support request ID ' . $id);
        require_once __DIR__ . '/../config/mail.php';
        $mailed = !empty($ticket['user_email']) && sendSupportReplyMail((string) $ticket['user_email'], (string) ($ticket['user_name'] ?? ''), (string) $ticket['subject'], trim((string) $_POST['body']), ef_url('support/ticket.php?id=' . $id));
        $_SESSION['success'] = 'Reply sent' . ($mailed ? ' and emailed to the user.' : '. (The email notification could not be sent; the user will see it in Help.)');
    } else {
        $_SESSION['error'] = $err ?? 'Could not send the reply.';
    }
} elseif ($do === 'close' || $do === 'reopen') {
    support_set_status($conn, $id, $do === 'close' ? 'closed' : 'open');
    admin_audit($conn, $admin['id'], 'ticket_' . $do, ($do === 'close' ? 'Closed' : 'Reopened') . ' support request ID ' . $id);
    $_SESSION['success'] = $do === 'close' ? 'Request closed.' : 'Request reopened.';
} else {
    $_SESSION['error'] = 'Unknown action.';
}
header('Location: ticket.php?id=' . $id);
exit();
