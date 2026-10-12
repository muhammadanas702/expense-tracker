<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';

// Logging out must be a POST with a valid token, otherwise any web page could log a visitor out
// just by embedding this address in an image or a link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $base_url . (isset($_SESSION['user_id']) ? "/dashboard.php" : "/auth/login.php"));
    exit();
}
require_post_csrf();

if (isset($_SESSION["user_id"])) {
    logAction($conn, $_SESSION["user_id"], 'logout', 'User logged out');
}

// destroy session and its cookie
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();

// redirect to login
header("Location: login.php");
exit();
