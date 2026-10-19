<?php
/** Staff side of Categories: add, move, turn on/off, delete. Needs the "cat" permission. */
require_once __DIR__ . '/auth.php';
admin_need('cat');
require_post_csrf();
require_once __DIR__ . '/../modules/categories/lib.php';
categories_ensure_schema($conn);

$do = (string) ($_POST['do'] ?? '');
if ($do === 'add') {
    [$ok, $err] = categories_add($conn, (string) ($_POST['name'] ?? ''), (string) ($_POST['icon'] ?? 'tag'));
    if ($ok) {
        admin_audit($conn, $admin['id'], 'cat_add', 'Added category "' . clean_text((string) $_POST['name'], CATEGORY_NAME_MAX) . '"');
        $_SESSION['success'] = 'Category added.';
    } else {
        $_SESSION['error'] = $err;
    }
} else {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $c = $id ? categories_get($conn, $id) : null;
    if (!$c) {
        $_SESSION['error'] = 'Category not found.';
    } elseif ($do === 'up' || $do === 'down') {
        categories_move($conn, $id, $do);
        $_SESSION['success'] = 'Order saved.';
    } elseif ($do === 'toggle' && $c['name'] !== CATEGORY_KEEP) {
        categories_set_active($conn, $id, !(int) $c['active']);
        admin_audit($conn, $admin['id'], 'cat_change', 'Turned category "' . $c['name'] . '" ' . ((int) $c['active'] ? 'off' : 'on'));
        $_SESSION['success'] = 'Updated.';
    } elseif ($do === 'delete' && categories_delete($conn, $id)) {
        admin_audit($conn, $admin['id'], 'cat_delete', 'Deleted category "' . $c['name'] . '"');
        $_SESSION['success'] = 'Category deleted.';
    } else {
        $_SESSION['error'] = '"' . CATEGORY_KEEP . '" cannot be changed.';
    }
}
header('Location: categories.php');
exit();
