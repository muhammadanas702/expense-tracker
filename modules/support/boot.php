<?php
/** Common start for the user-facing Support pages: session, login check, layout, helpers. */
require_once __DIR__ . '/../../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/logging.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
$user_id = (int) $_SESSION['user_id'];
$u = $conn->prepare('SELECT name, is_admin FROM users WHERE id = ?');
$u->execute([$user_id]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
support_ensure_schema($conn);

function support_back(string $path): void
{
    global $base_url;
    header('Location: ' . $base_url . '/' . ltrim($path, '/'));
    exit();
}
