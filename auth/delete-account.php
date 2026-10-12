<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$error = '';

$stmt = $conn->prepare("SELECT id, name, password, is_admin FROM users WHERE id=?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $password = (string) ($_POST['password'] ?? '');
    $rlKey = rl_key('delete-account', (string) $user_id);

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif (rl_blocked($conn, 'delete_account', $rlKey, 5, 900)) {
        $error = "Too many wrong attempts. Please wait 15 minutes and try again.";
    } elseif (!password_verify($password, $user['password'])) {
        rl_hit($conn, 'delete_account', $rlKey);
        $error = "Wrong password. Your account was NOT deleted.";
    } else {
        $isLastAdmin = false;
        if ((int) $user['is_admin'] === 1) {
            $adminCount = (int) $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
            $isLastAdmin = $adminCount <= 1;
        }

        if ($isLastAdmin) {
            $error = "You are the only administrator. Make another user an admin before deleting this account.";
        } else {
            /* DELETE ALL USER DATA (all or nothing) */
            $conn->beginTransaction();
            try {
                foreach (['income', 'expenses', 'user_monthly_currency', 'user_logs'] as $table) {
                    $conn->prepare("DELETE FROM `$table` WHERE user_id=?")->execute([$user_id]);
                }
                $conn->prepare("DELETE FROM users WHERE id=?")->execute([$user_id]);
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                error_log('ExpenseFlow account deletion failed: ' . $e->getMessage());
                http_response_code(500);
                exit('Could not delete the account. Nothing was changed. Please try again later.');
            }

            $_SESSION = [];
            session_destroy();
            secure_session_start();
            session_regenerate_id(true);
            $_SESSION['reset_message'] = "Your account and all its data were deleted.";
            $_SESSION['reset_type'] = "success";
            header("Location: login.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Account</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 400px; max-width: calc(100% - 32px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        h2 { margin-top: 0; color: #b91c1c; }
        input { width: 100%; padding: 0.7rem; margin: 0.8rem 0; border: 1px solid #cbd5e1; border-radius: 40px; box-sizing: border-box; }
        button { background: #dc2626; color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; font-weight: 600; cursor: pointer; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem 0.8rem; border-radius: 20px; margin-bottom: 1rem; }
        .cancel { display: block; text-align: center; margin-top: 1rem; color: #0f766e; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>🗑 Delete account</h2>
    <p>This permanently deletes your account, <strong>all your income and expenses</strong>, and your activity history. It cannot be undone.</p>
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST" onsubmit="return confirm('Delete your account permanently?');">
        <?= csrf_field() ?>
        <label for="password">Enter your password to confirm</label>
        <input type="password" id="password" name="password" placeholder="Your password" required autofocus>
        <button type="submit">Delete my account permanently</button>
    </form>
    <a class="cancel" href="<?= e($base_url) ?>/dashboard.php">← Cancel, keep my account</a>
</div>
</body>
</html>
