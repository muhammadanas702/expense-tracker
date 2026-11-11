<?php
/** Features on/off: switch one feature, or turn everything back on. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/features/lib.php';

$do = (string) ($_POST['do'] ?? '');
$flat = features_flat();
if ($do === 'all_on') {
    $n = count(features_off_list(true));
    if (features_write([])) {
        if ($n) {
            admin_audit($conn, $admin['id'], 'feature_change', 'Features: everything switched back on');
        }
        $_SESSION['success'] = $n ? 'Everything is switched on again.' : 'Everything was already on.';
    } else {
        $_SESSION['error'] = 'Could not save: the storage folder is not writable.';
    }
} elseif (($do === 'on' || $do === 'off') && isset($flat[(string) ($_POST['id'] ?? '')])) {
    $id = (string) $_POST['id'];
    $was = in_array($id, features_off_list(true), true);
    if ($was === ($do === 'off')) {
        $_SESSION['success'] = 'Nothing changed.';
    } elseif (features_set($id, $do === 'on')) {
        admin_audit($conn, $admin['id'], 'feature_change', 'Features: ' . $flat[$id]['label'] . ' switched ' . $do);
        $_SESSION['success'] = $flat[$id]['label'] . ' is now ' . ($do === 'on' ? 'ON.' : 'OFF.');
    } else {
        $_SESSION['error'] = 'Could not save: the storage folder is not writable.';
    }
} else {
    $_SESSION['error'] = 'Unknown feature.';
}
header('Location: features.php' . (isset($flat[(string) ($_POST['id'] ?? '')]) ? '#f-' . $_POST['id'] : ''));
exit();
