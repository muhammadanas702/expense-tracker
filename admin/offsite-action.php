<?php
/** Staff side of the off-site (GitHub) backup: save + test, send now, switch off. Needs the "backup" permission. */
require_once __DIR__ . '/auth.php';
admin_need('backup');
require_post_csrf();
require_once __DIR__ . '/../modules/backup/offsite.php';

$do = (string) ($_POST['do'] ?? '');
$c = offsite_config();
if ($do === 'save') {
    $repo = trim((string) ($_POST['repo'] ?? ''));
    $repo = preg_replace('#^https?://github\.com/#i', '', $repo);
    $repo = preg_replace('#\.git$#', '', trim($repo, '/'));
    $token = trim((string) ($_POST['token'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');
    $new = $c;
    $new['repo'] = $repo;
    if ($token !== '') {
        $new['token'] = $token;
    }
    if ($pass !== '') {
        $new['pass'] = $pass;
    }
    if (!offsite_valid_repo($repo)) {
        $_SESSION['error'] = 'Type the repository as owner/name, for example muhammadanas702/expenseflow-backups.';
    } else {
        [$ok, $msg] = offsite_test($new);
        if ($ok) {
            $new['on'] = true;
            $new['last_error'] = '';
            $new['last_try'] = 0;
            if (offsite_save($new)) {
                admin_audit($conn, $admin['id'], 'backup_offsite', 'Switched on off-site backup to ' . $repo);
                $_SESSION['success'] = $msg . ' Off-site backup is on.';
            } else {
                $_SESSION['error'] = 'Could not save the settings (the storage folder is not writable).';
            }
        } else {
            $_SESSION['error'] = $msg;
        }
    }
} elseif ($do === 'push') {
    $newest = backup_list()[0]['name'] ?? null;
    if (!offsite_ready($c)) {
        $_SESSION['error'] = 'Off-site backup is not switched on.';
    } elseif ($newest === null) {
        $_SESSION['error'] = 'There is no backup yet. Create one first.';
    } else {
        [$ok, $msg] = offsite_push($newest);
        if ($ok) {
            admin_audit($conn, $admin['id'], 'backup_offsite', 'Sent a backup to the off-site repository');
            $_SESSION['success'] = $msg;
        } else {
            $_SESSION['error'] = $msg;
        }
    }
} elseif ($do === 'off') {
    $c['on'] = false;
    $c['token'] = '';
    $c['pass'] = '';
    offsite_save($c);
    admin_audit($conn, $admin['id'], 'backup_offsite', 'Switched off off-site backup and removed the saved token');
    $_SESSION['success'] = 'Off-site backup is off and the token and passphrase were removed from this server.';
} else {
    $_SESSION['error'] = 'Unknown action.';
}
header('Location: backups.php#offsite');
exit();
