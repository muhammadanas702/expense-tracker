<?php
// Serves one receipt picture, only to members of the expense's group.
require_once __DIR__ . '/../modules/shared/boot.php';
if (!function_exists('receipts_get')) {
    http_response_code(404);
    exit('Not found.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$r = $id ? receipts_get($conn, $id) : null;
$path = ($r && preg_match('/^[0-9a-f]{32}$/', $r['stored'])) ? receipts_dir() . '/' . $r['stored'] : '';
// Same answer for "does not exist" and "not your group": nothing is revealed.
if (!$r || !shared_group_for($conn, (int) $r['group_id'], $user_id) || !is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}
header('Content-Type: ' . ($r['mime'] === 'image/png' || $r['mime'] === 'image/webp' ? $r['mime'] : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
header('Content-Disposition: inline; filename="receipt-' . (int) $r['expense_id'] . '.jpg"');
readfile($path);
