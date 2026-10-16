<?php
/** Common start for every Shared page: session, login check, user row, schema, layout, helpers. */
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
shared_ensure_schema($conn);

/** Round avatar with initials; the colour depends on the position so members look different. */
function shared_avatar(string $name, int $i = 0): string
{
    $colors = ['#0f766e', '#1d4ed8', '#F59E0B', '#f97316', '#4DA8DA', '#a855f7'];
    return '<i class="av" style="background:' . $colors[$i % count($colors)] . '">' . e(ef_initials($name)) . '</i>';
}

function shared_back(string $path): void
{
    global $base_url;
    header('Location: ' . $base_url . '/' . ltrim($path, '/'));
    exit();
}
