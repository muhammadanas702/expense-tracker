<?php
/**
 * Start of every admin page: login, staff role check (looked up in the database on every
 * request, never trusted from the session), helpers.
 * After including this file: $conn (PDO), $admin = ['id','name','role'], $is_admin (bool, legacy).
 */
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/admin/lib.php';
require_once __DIR__ . '/../modules/admin/layout.php';
admin_ensure_schema($conn);

$stmt = $conn->prepare("SELECT id, name, role FROM users WHERE id = ?");
$stmt->execute([(int) $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin || !admin_is_staff((string) $admin['role'])) {
    http_response_code(403);
    exit("Access denied. Staff only.");
}
$admin['id'] = (int) $admin['id'];
$is_admin = true;

/** Stops the request unless the staff member has this permission ('SA' = Super Admin only). */
function admin_need(string $perm): void
{
    global $conn, $admin;
    if (!admin_can($conn, $admin['role'], $perm)) {
        http_response_code(403);
        $_SESSION['error'] = 'Your role does not allow this.';
        header('Location: ' . dirname($_SERVER['SCRIPT_NAME']) . '/index.php');
        exit();
    }
}
