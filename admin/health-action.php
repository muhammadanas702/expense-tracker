<?php
/** Website health: "Run page speed check" and "Save a check now". */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/health/lib.php';
health_ensure_schema($conn);

$do = (string) ($_POST['do'] ?? '');
if ($do === 'probe') {
    $rows = health_probe((string) $base_url);
    if (!$rows) {
        $_SESSION['error'] = 'The page check could not start (the site address is not usable).';
    } else {
        $bad = count(array_filter($rows, fn($r) => $r['state'] === 'bad'));
        health_record($conn, 'manual', null, $bad === 0, 0, $rows, 'probe');
        admin_audit($conn, $admin['id'], 'health_probe', 'Page speed check: ' . ($bad === 0 ? 'all pages answered' : $bad . ' page(s) slow or failing'));
        $_SESSION[$bad === 0 ? 'success' : 'error'] = $bad === 0 ? 'All pages answered.' : $bad . ' page(s) were slow or did not answer. See the list below.';
    }
} elseif ($do === 'record') {
    $ms = health_db_ms($conn);
    health_record($conn, 'manual', $ms, $ms !== null);
    $_SESSION['success'] = 'A check was saved to the history.';
}
header('Location: health.php');
exit();
