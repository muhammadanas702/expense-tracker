<?php
/** A user hides an announcement for themselves (POST + CSRF). */
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/announce/lib.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
require_post_csrf();
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id) {
    announce_dismiss($conn, (int) $_SESSION['user_id'], $id);
}
$back = $_SERVER['HTTP_REFERER'] ?? '';
$host = parse_url($back, PHP_URL_HOST);
header('Location: ' . (($host && $host === ($_SERVER['HTTP_HOST'] ?? '')) || (!$host && $back !== '' && $back[0] === '/' && substr($back, 0, 2) !== '//') ? $back : $base_url . '/dashboard.php'));
exit();
