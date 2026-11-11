<?php
/** Staff side of Features: turn one option on or off. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/features/lib.php';

$id = (string) ($_POST['id'] ?? '');
$do = (string) ($_POST['do'] ?? '');
$cat = features_catalog();
if (!isset($cat[$id]) || !in_array($do, ['on', 'off'], true)) {
    $_SESSION['error'] = 'Unknown option.';
    header('Location: features.php');
    exit();
}
$want = $do === 'on';
$isSaved = in_array($id, features_saved_off(true), true); // saved as off (an option can also be off only because the one it needs is off)
if ($want === !$isSaved) {
    $_SESSION['success'] = 'Nothing changed.';
} elseif (features_set($id, $want)) {
    admin_audit($conn, $admin['id'], 'feature_change', $cat[$id]['label'] . ' turned ' . ($want ? 'on' : 'off'));
    $_SESSION['success'] = $cat[$id]['label'] . ($want ? ' is on again.' : ' is off now. Nobody can open it until you turn it on.');
} else {
    $_SESSION['error'] = 'Could not save the switch (the storage folder is not writable).';
}
header('Location: features.php#f-' . $id);
exit();
