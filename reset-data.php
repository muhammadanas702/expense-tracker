<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

// Wiping data must be a POST with a valid CSRF token (never a plain link).
require_post_csrf();

$user_id = (int) $_SESSION["user_id"];
$type = (string) ($_POST["type"] ?? '');

// Which tables each reset type clears.
$targets = [
    "income"  => ["income", "user_monthly_currency"],
    "expense" => ["expenses"],
    "all"     => ["income", "expenses", "user_monthly_currency"],
];

if (isset($targets[$type])) {
    $conn->beginTransaction();
    try {
        foreach ($targets[$type] as $table) {
            $conn->prepare("DELETE FROM `$table` WHERE user_id = ?")->execute([$user_id]);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('ExpenseFlow data reset failed: ' . $e->getMessage());
        $_SESSION['error'] = "Could not reset your data. Nothing was changed.";
        header("Location: dashboard.php");
        exit();
    }

    logAction($conn, $user_id, 'reset_data', "Reset type: $type");
}

header("Location: dashboard.php");
exit();
