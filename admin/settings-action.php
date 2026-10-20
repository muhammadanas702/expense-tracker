<?php
/** Staff side of Settings: save. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/settings/lib.php';

[$vals, $err] = settings_validate($_POST);
if ($err) {
    $_SESSION['error'] = $err;
    $_SESSION['settings_old'] = ['maintenance' => !empty($_POST['maintenance']) ? '1' : '0', 'signups' => !empty($_POST['signups']) ? '1' : '0', 'maintenance_msg' => mb_substr((string) ($_POST['maintenance_msg'] ?? ''), 0, 400)];
} else {
    $changes = settings_save($conn, $vals);
    if ($changes) {
        admin_audit($conn, $admin['id'], 'settings_change', 'Settings: ' . implode(', ', $changes));
        $_SESSION['success'] = 'Settings saved.';
    } else {
        $_SESSION['success'] = 'Nothing changed.';
    }
}
header('Location: settings.php');
exit();
