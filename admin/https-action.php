<?php
/** Staff side of "Force HTTPS": on / off. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/settings/lib.php';
$on = ($_POST['do'] ?? '') === 'on';
$err = settings_force_https_set($on);
if ($err) {
    $_SESSION['error'] = $err;
} else {
    admin_audit($conn, $admin['id'], 'settings_change', 'Force HTTPS turned ' . ($on ? 'on' : 'off'));
    $_SESSION['success'] = $on ? 'Force HTTPS is on: http:// addresses now redirect to https://.' : 'Force HTTPS is off.';
}
header('Location: settings.php');
exit();
