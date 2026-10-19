<?php
/** Staff side of Announcements: save, turn on/off, delete. Needs the "bc" permission. */
require_once __DIR__ . '/auth.php';
admin_need('bc');
require_post_csrf();
require_once __DIR__ . '/../modules/announce/lib.php';
announce_ensure_schema($conn);

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$do = (string) ($_POST['do'] ?? '');
$a = $id ? announce_get($conn, $id) : null;

if ($do === 'save') {
    [$d, $err] = announce_validate($_POST);
    if ($id && !$a) {
        $_SESSION['error'] = 'Announcement not found.';
    } elseif ($err) {
        $_SESSION['error'] = $err;
        $_SESSION['announce_old'] = ['id' => $id, 'title' => (string) ($_POST['title'] ?? ''), 'body' => (string) ($_POST['body'] ?? ''), 'level' => (string) ($_POST['level'] ?? 'info'), 'active' => !empty($_POST['active']) ? 1 : 0, 'starts_at' => (string) ($_POST['starts_at'] ?? ''), 'ends_at' => (string) ($_POST['ends_at'] ?? '')];
    } else {
        $nid = announce_save($conn, $id ?: null, $d, $admin['id']);
        admin_audit($conn, $admin['id'], $id ? 'announce_edit' : 'announce_add', ($id ? 'Edited' : 'Added') . ' announcement ID ' . $nid);
        $_SESSION['success'] = 'Announcement saved.';
    }
} elseif (($do === 'toggle' || $do === 'delete') && $a) {
    if ($do === 'toggle') {
        $conn->prepare('UPDATE announcements SET active = 1 - active WHERE id = ?')->execute([$id]);
        admin_audit($conn, $admin['id'], 'announce_toggle', 'Turned announcement ID ' . $id . ((int) $a['active'] ? ' off' : ' on'));
        $_SESSION['success'] = 'Updated.';
    } else {
        announce_delete($conn, $id);
        admin_audit($conn, $admin['id'], 'announce_delete', 'Deleted announcement ID ' . $id);
        $_SESSION['success'] = 'Announcement deleted.';
    }
} else {
    $_SESSION['error'] = 'Announcement not found.';
}
header('Location: announcements.php');
exit();
