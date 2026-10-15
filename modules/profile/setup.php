<?php
// First-run setup: base currency and savings goal. Shown as a card on the dashboard until it is done or skipped.
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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['skip'])) {
        profile_save_settings($conn, $user_id, ['setup_done' => 1]);
        header('Location: ' . $base_url . '/dashboard.php');
        exit();
    } else {
        $goal = filter_var($_POST['savings_goal'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        $cur = normalize_currency($_POST['currency'] ?? '');
        if ($goal === false || $goal === null) {
            $error = 'Savings goal must be a whole number from 0 to 100.';
        } elseif ($cur === null) {
            $error = 'Please choose a supported currency.';
        } else {
            $ym = date('Y-m');
            $has = $conn->prepare('SELECT 1 FROM user_monthly_currency WHERE user_id = ? AND `year_month` = ?');
            $has->execute([$user_id, $ym]);
            if ($has->fetchColumn()) {
                $conn->prepare('UPDATE user_monthly_currency SET base_currency = ? WHERE user_id = ? AND `year_month` = ?')->execute([$cur, $user_id, $ym]);
            } else {
                $conn->prepare('INSERT INTO user_monthly_currency (user_id, `year_month`, base_currency) VALUES (?,?,?)')->execute([$user_id, $ym, $cur]);
            }
            profile_save_settings($conn, $user_id, ['savings_goal' => $goal, 'setup_done' => 1]);
            logAction($conn, $user_id, 'setup_done', "Base $cur, goal $goal%");
            $_SESSION['success'] = 'All set! Add your first income or expense.';
            header('Location: ' . $base_url . '/dashboard.php');
            exit();
        }
    }
}

$settings = profile_settings($conn, $user_id);
ef_page_start(['title' => 'Quick setup', 'sub' => 'Two questions, 20 seconds', 'active' => '', 'user' => $user]);
?>
<div class="g" style="max-width:520px;margin:0 auto;padding:22px">
  <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="fl"><label for="currency">Your main currency</label><select class="inp" id="currency" name="currency"><?= currency_select_options('PKR') ?></select></div>
    <div class="fl"><label for="savings_goal">How much of your income would you like to save? (%)</label>
      <input class="inp" id="savings_goal" type="number" name="savings_goal" min="0" max="100" step="1" value="<?= (int) $settings['savings_goal'] ?>" required></div>
    <button class="btn" type="submit" style="width:100%;padding:14px">Finish setup</button>
    <button class="btn o" type="submit" name="skip" value="1" formnovalidate style="width:100%;margin-top:10px">Skip for now</button>
  </form>
</div>
<?php ef_page_end(['active' => '']);
