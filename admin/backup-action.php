<?php
/** Staff side of Backups: create, download, delete. Needs the "backup" permission. */
require_once __DIR__ . '/auth.php';
admin_need('backup');
require_post_csrf();
require_once __DIR__ . '/../modules/backup/lib.php';

$do = (string) ($_POST['do'] ?? '');
if ($do === 'create') {
    [$name, $err] = backup_create($conn);
    if ($name) {
        admin_audit($conn, $admin['id'], 'backup_create', 'Created a database backup');
        $_SESSION['success'] = 'Backup created.';
    } else {
        $_SESSION['error'] = $err;
    }
} elseif ($do === 'download' && ($n = backup_valid_name($_POST['name'] ?? ''))) {
    admin_audit($conn, $admin['id'], 'backup_download', 'Downloaded a database backup');
    $path = backup_dir() . '/' . $n;
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $n . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit();
} elseif ($do === 'delete' && ($n = backup_valid_name($_POST['name'] ?? ''))) {
    backup_delete($n);
    admin_audit($conn, $admin['id'], 'backup_delete', 'Deleted a database backup');
    $_SESSION['success'] = 'Backup deleted.';
} else {
    $_SESSION['error'] = 'Backup not found.';
}
header('Location: backups.php');
exit();
