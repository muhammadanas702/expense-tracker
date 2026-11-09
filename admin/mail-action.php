<?php
/** Email log: send one test e-mail so the admin can see at once whether sending works (and why not). */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../config/mail.php';

$to = trim((string) ($_POST['to'] ?? ''));
if ($to === '' || strlen($to) > 190 || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = 'Enter a valid e-mail address.';
} else {
    $name = (string) ($admin['name'] ?? 'there');
    $ok = ef_send_mail(
        $to,
        $name,
        'ExpenseFlow test e-mail',
        ef_mail_html('E-mail works', $name, "This is a test message from the ExpenseFlow admin panel.\nIf you can read it, the site can send e-mail.", '', 'Sent from Admin > Email log.', 'none'),
        'This is a test message from the ExpenseFlow admin panel. If you can read it, the site can send e-mail.',
        true
    );
    admin_audit($conn, $admin['id'], 'mail_test', 'Test e-mail ' . ($ok ? 'sent' : 'failed') . ' to ' . ef_mail_mask($to));
    if ($ok) {
        $_SESSION['success'] = 'Test e-mail sent. Check the inbox and the spam folder.';
    } else {
        $_SESSION['error'] = 'Not sent: ' . (ef_mail_last_error() ?: 'unknown error');
    }
}
header('Location: mail.php');
exit();
