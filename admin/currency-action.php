<?php
/** Staff side of Currencies: save which currencies are shown. Needs the "cur" permission. */
require_once __DIR__ . '/auth.php';
admin_need('cur');
require_post_csrf();
require_once __DIR__ . '/../modules/currencies/lib.php';

$shown = array_map('strtoupper', array_filter((array) ($_POST['show'] ?? []), 'is_string'));
$hide = array_diff(array_keys(app_currencies()), $shown);
[$off, $on] = currencies_save_hidden($conn, $hide);
if ($off || $on) {
    $t = [];
    if ($off) {
        $t[] = 'hidden ' . implode(', ', $off);
    }
    if ($on) {
        $t[] = 'shown ' . implode(', ', $on);
    }
    admin_audit($conn, $admin['id'], 'cur_change', 'Currencies: ' . implode('; ', $t));
    $_SESSION['success'] = 'Currencies saved.';
} else {
    $_SESSION['success'] = 'Nothing changed.';
}
header('Location: currencies.php');
exit();
