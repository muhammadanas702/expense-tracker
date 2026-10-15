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
            require_once __DIR__ . '/../modules/personal/lib.php';
            personal_ensure_schema($conn);
            require_once __DIR__ . '/../modules/profile/lib.php';
            profile_ensure_schema($conn); // makes sure the extra tables exist before they are cleaned
            $conn->beginTransaction();
            try {
                foreach (['income', 'expenses', 'user_monthly_currency', 'user_logs', 'recurring_items', 'user_settings'] as $table) {
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

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php ef_auth_start(['title' => 'Delete account', 'split' => false]); ?>
<div class="big wr"><?= ef_icon('warn') ?></div>
<h2 class="c2">Delete account permanently?</h2>
<p class="s3 c2">This cannot be undone. The following will be erased:</p>
<div style="margin-bottom:16px">
    <?= ef_check('All income and expense records') ?>
    <?= ef_check('Your activity history and settings') ?>
    <?= ef_check('Your profile and login') ?>
</div>
<?= ef_msg($error) ?>
<form method="POST" onsubmit="return confirm('Delete your account permanently?');">
    <?= csrf_field() ?>
    <?= ef_field('ENTER YOUR PASSWORD TO CONFIRM', 'password', 'lock', 'password', '', ['placeholder' => 'Your password', 'required' => true, 'autofocus' => true, 'autocomplete' => 'current-password']) ?>
    <div class="GE">
        <a class="btn lg o" style="width:100%" href="<?= e($base_url) ?>/dashboard.php">Cancel</a>
        <button class="btn lg e2" style="width:100%" type="submit">Delete</button>
    </div>
</form>
<?php ef_auth_end(['split' => false]); ?>
