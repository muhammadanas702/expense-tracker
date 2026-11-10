<?php
/** Staff side of Branding: save or reset. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/branding/lib.php';

$do = (string) ($_POST['do'] ?? 'save');
if ($do === 'reset') {
    $had = brand_active();
    @unlink(BRAND_FILE);
    brand_remove_logo();
    brand_data(true);
    if ($had) {
        admin_audit($conn, $admin['id'], 'brand_change', 'Branding: reset to the built-in look');
    }
    $_SESSION['success'] = $had ? 'Back to the built-in look.' : 'Nothing changed.';
    header('Location: branding.php');
    exit();
}

[$vals, $err] = brand_validate($_POST);
if (!$err && !empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? 0) !== UPLOAD_ERR_NO_FILE) {
    $err = brand_save_logo($_FILES['logo']);
    $logoChanged = $err === null;
} else {
    $logoChanged = false;
}
if ($err) {
    $_SESSION['error'] = $err;
    $_SESSION['brand_old'] = ['name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, BRAND_NAME_MAX), 'tagline' => mb_substr(trim((string) ($_POST['tagline'] ?? '')), 0, BRAND_TAGLINE_MAX), 'a1' => mb_substr(trim((string) ($_POST['a1'] ?? '')), 0, 7), 'a2' => mb_substr(trim((string) ($_POST['a2'] ?? '')), 0, 7)];
} else {
    $changes = brand_save($vals);
    if ($changes === false) {
        $_SESSION['error'] = 'Could not save: the storage folder is not writable.';
    } else {
        if (!empty($_POST['remove_logo']) && !$logoChanged && brand_has_logo() && brand_remove_logo()) {
            $changes[] = 'logo removed';
        }
        if ($logoChanged) {
            $changes[] = 'logo uploaded';
        }
        if ($changes) {
            admin_audit($conn, $admin['id'], 'brand_change', 'Branding: ' . implode(', ', $changes));
            $_SESSION['success'] = 'Branding saved.';
        } else {
            $_SESSION['success'] = 'Nothing changed.';
        }
    }
}
header('Location: branding.php');
exit();
