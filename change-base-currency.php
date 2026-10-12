<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];

$month = '';
$year = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid()) {
        $_SESSION['error'] = "Your session expired. Please try again.";
    } else {
        // Only currencies from the supported list are accepted.
        $new_base = normalize_currency($_POST['new_base_currency'] ?? '');
        $year_month = (string) ($_POST['year_month'] ?? '');

        if ($new_base !== null && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $year_month, $m)) {
            $year = $m[1];
            $month = (string) (int) $m[2];

            $stmt = $conn->prepare("SELECT 1 FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?");
            $stmt->execute([$user_id, $year_month]);

            if ($stmt->fetchColumn()) {
                $conn->prepare("UPDATE `user_monthly_currency` SET `base_currency` = ? WHERE `user_id` = ? AND `year_month` = ?")
                    ->execute([$new_base, $user_id, $year_month]);
            } else {
                $conn->prepare("INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)")
                    ->execute([$user_id, $year_month, $new_base]);
            }

            logAction($conn, $user_id, 'change_base_currency', "Changed base currency to $new_base for $year_month");

            $_SESSION['success'] = "Base currency changed to $new_base. All totals recalculated.";
        } else {
            $_SESSION['error'] = "Invalid request.";
        }
    }
}

$redirect = "dashboard.php";
if ($month !== '' && $year !== '') {
    $redirect .= "?month=" . urlencode($month) . "&year=" . urlencode($year);
}
header("Location: $redirect");
exit();
