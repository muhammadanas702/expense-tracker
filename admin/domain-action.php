<?php
/** Staff side of Admin > Domain: check a domain, make it the main address, go back to the free address. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/site/host.php';

$do = (string) ($_POST['do'] ?? '');
$host = strtolower(trim((string) ($_POST['host'] ?? '')));
$_SESSION['domain_check'] = null;
if ($do === 'check') {
    $steps = site_check($host);
    $_SESSION['domain_check'] = ['host' => $host, 'steps' => $steps, 'ok' => site_check_passed($steps)];
    $_SESSION[site_check_passed($steps) ? 'success' : 'error'] = site_check_passed($steps) ? $host . ' is ready. You can make it the main address now.' : 'Not ready yet. See the three checks below.';
} elseif ($do === 'switch') {
    $steps = site_check($host);
    if (!site_check_passed($steps)) {
        $_SESSION['domain_check'] = ['host' => $host, 'steps' => $steps, 'ok' => false];
        $_SESSION['error'] = 'The address was not switched: ' . $host . ' did not pass the live check.';
    } elseif (site_save(['host' => $host, 'live' => true, 'since' => time()])) {
        admin_audit($conn, $admin['id'], 'domain_change', 'Made ' . $host . ' the main address');
        $_SESSION['success'] = $host . ' is now the main address. Everyone who opens the old address is sent there. Open it now and sign in again (sign-ins do not move between addresses).';
    } else {
        $_SESSION['error'] = 'Could not save the setting (the storage folder is not writable).';
    }
} elseif ($do === 'revert') {
    $old = site_config();
    if (site_save(['host' => $old['host'], 'live' => false, 'since' => 0])) {
        admin_audit($conn, $admin['id'], 'domain_change', 'Went back to the free address ' . SITE_LEGACY_HOST);
        $_SESSION['success'] = 'Back to ' . SITE_LEGACY_HOST . '. The own domain is no longer forced.';
    } else {
        $_SESSION['error'] = 'Could not save the setting (the storage folder is not writable).';
    }
} else {
    $_SESSION['error'] = 'Unknown action.';
}
header('Location: domain.php');
exit();
