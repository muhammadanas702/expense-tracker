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
    foreach (array_keys(BRAND_IMAGE_SLOTS) as $slot) {
        brand_remove_image($slot);
    }
    brand_data(true);
    if ($had) {
        admin_audit($conn, $admin['id'], 'brand_change', 'Branding: reset to the built-in look');
    }
    $_SESSION['success'] = $had ? 'Back to the built-in look.' : 'Nothing changed.';
    header('Location: branding.php');
    exit();
}

if ($do === 'image' || $do === 'image_remove') {
    $slot = (string) ($_POST['slot'] ?? '');
    if (!isset(BRAND_IMAGE_SLOTS[$slot])) {
        $_SESSION['error'] = 'Unknown picture.';
    } elseif ($do === 'image_remove') {
        if (brand_image_file($slot) !== null && brand_remove_image($slot)) {
            admin_audit($conn, $admin['id'], 'brand_change', 'Branding: ' . BRAND_IMAGE_SLOTS[$slot]['label'] . ' restored to the built-in picture');
            $_SESSION['success'] = 'Built-in picture restored.';
        } else {
            $_SESSION['success'] = 'Nothing changed.';
        }
    } elseif (empty($_FILES['img']['name'])) {
        $_SESSION['error'] = 'Choose a picture first.';
    } else {
        $err = brand_save_image($slot, $_FILES['img']);
        if ($err) {
            $_SESSION['error'] = $err;
        } else {
            admin_audit($conn, $admin['id'], 'brand_change', 'Branding: ' . BRAND_IMAGE_SLOTS[$slot]['label'] . ' replaced');
            $_SESSION['success'] = 'Picture saved. It shows on the website now.';
        }
    }
    header('Location: branding.php#pictures');
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
