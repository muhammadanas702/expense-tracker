<?php
/** Staff side of Security: block or unblock an IP address. Needs the "block" permission (viewing the page needs "log"). */
require_once __DIR__ . '/auth.php';
admin_need('block');
require_post_csrf();
require_once __DIR__ . '/../modules/security/lib.php';

$do = (string) ($_POST['do'] ?? '');
$ip = (string) ($_POST['ip'] ?? '');
if ($do === 'block') {
    [$ok, $err] = security_block($conn, $ip, (string) ($_POST['reason'] ?? ''), (string) ($_POST['duration'] ?? '1d'), $admin['id']);
    if ($ok) {
        $d = SECURITY_DURATIONS[$_POST['duration'] ?? ''][0] ?? SECURITY_DURATIONS['1d'][0];
        admin_audit($conn, $admin['id'], 'ip_block', 'Blocked ' . security_valid_ip($ip) . ' (' . $d . ')');
        $_SESSION['success'] = 'Address blocked.';
    } else {
        $_SESSION['error'] = $err;
    }
} elseif ($do === 'unblock') {
    if (security_unblock($conn, $ip)) {
        admin_audit($conn, $admin['id'], 'ip_unblock', 'Unblocked ' . security_valid_ip($ip));
        $_SESSION['success'] = 'Address unblocked.';
    } else {
        $_SESSION['error'] = 'That address is not blocked.';
    }
} else {
    $_SESSION['error'] = 'Unknown action.';
}
header('Location: security.php');
exit();
