<?php
require_once __DIR__ . '/includes/security.php';
secure_session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/logging.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/modules/profile/lib.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: auth/login.php");
    exit();
}
$user_id = (int) $_SESSION["user_id"];
$error = "";
$success = "";

$stmt = $conn->prepare("SELECT name, email, is_admin FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: auth/login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif (isset($_POST['update_profile'])) {
        $new_name = clean_text($_POST['name'] ?? '', 100);
        if ($new_name === null) {
            $error = "Please enter a name (up to 100 characters).";
        } else {
            $conn->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$new_name, $user_id]);
            $_SESSION["user_name"] = $new_name;
            $user['name'] = $new_name;
            $success = "Profile updated successfully.";
            logAction($conn, $user_id, 'update_profile', "Updated name");
        }
    } elseif (isset($_POST['update_settings'])) {
        $goal = filter_var($_POST['savings_goal'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($goal === false || $goal === null) {
            $error = "Savings goal must be a whole number from 0 to 100.";
        } else {
            profile_save_settings($conn, $user_id, ['savings_goal' => $goal, 'notifications' => !empty($_POST['notifications'])]);
            $success = "Preferences saved.";
            logAction($conn, $user_id, 'update_profile', "Updated preferences");
        }
    } elseif (isset($_POST['update_password'])) {
        $current  = (string) ($_POST['current_password'] ?? '');
        $new_pass = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        $key = rl_key((string) $user_id);
        if (rl_blocked($conn, 'change_password', $key, 5, 900)) {
            $error = "Too many attempts. Please try again in a few minutes.";
        } else {
            $pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $pass_stmt->execute([$user_id]);
            $hashed = (string) $pass_stmt->fetchColumn();

            if (!password_verify($current, $hashed)) {
                rl_hit($conn, 'change_password', $key);
                $error = "Current password is incorrect.";
            } elseif ($new_pass !== $confirm) {
                $error = "New passwords do not match.";
            } elseif (($policy = password_policy_error($new_pass)) !== null) {
                $error = $policy;
            } else {
                $conn->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user_id]);
                rl_clear($conn, 'change_password', $key);
                session_regenerate_id(true);
                $success = "Password changed successfully.";
                logAction($conn, $user_id, 'update_profile', "Changed password");
            }
        }
    }
}
?>
<?php
$settings = profile_settings($conn, $user_id);
$csrf = csrf_token();
if ($success !== '') { $_SESSION['success'] = $success; }
if ($error !== '') { $_SESSION['error'] = $error; }
ef_page_start(['title' => 'Profile & Settings', 'sub' => 'Account, goals and data', 'active' => '', 'user' => $user]);
?>
<div class="GE">
  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="g card">
      <div style="display:flex;gap:14px;align-items:center;margin-bottom:14px">
        <i class="av" style="width:56px;height:56px;font-size:1.1rem"><?= e(ef_initials($user['name'])) ?></i>
        <div><b style="font-size:1.05rem"><?= e($user['name']) ?></b><div class="k"><?= e($user['email']) ?></div></div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="fl"><label for="name">Name</label><input class="inp" id="name" type="text" name="name" maxlength="100" value="<?= e($user['name']) ?>" required></div>
        <div class="fl"><label for="email">Email</label><input class="inp" id="email" type="email" value="<?= e($user['email']) ?>" readonly></div>
        <button class="btn" type="submit" name="update_profile" value="1">Update profile</button>
      </form>
    </div>

    <div class="g card">
      <div class="t">Preferences</div>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="fl"><label for="savings_goal">Monthly savings goal (% of income)</label>
          <input class="inp" id="savings_goal" type="number" name="savings_goal" min="0" max="100" step="1" value="<?= (int) $settings['savings_goal'] ?>" required></div>
        <label class="row" style="cursor:pointer">
          <span><?= ef_icon('bell') ?> Notifications<small>Reminders for shared bills and settle-ups (they start with the Shared feature)</small></span>
          <b><input class="swc" type="checkbox" name="notifications" value="1"<?= $settings['notifications'] ? ' checked' : '' ?>><i class="sw"></i></b>
        </label>
        <div class="row"><span><?= ef_icon('globe') ?> Base currency<small>Chosen per month on the dashboard</small></span><b><a class="lnk" href="<?= e($base_url) ?>/dashboard.php#base">Change</a></b></div>
        <button class="btn" type="submit" name="update_settings" value="1" style="margin-top:10px">Save preferences</button>
      </form>
    </div>

    <div class="g card">
      <div class="t">Change password</div>
      <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fl"><label for="current_password">Current password</label><input class="inp" id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="fl"><label for="new_password">New password</label><input class="inp" id="new_password" type="password" name="new_password" autocomplete="new-password" required><small class="k">At least 8 characters with letters and numbers.</small></div>
        <div class="fl"><label for="confirm_password">Confirm new password</label><input class="inp" id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" required></div>
        <button class="btn" type="submit" name="update_password" value="1">Change password</button>
      </form>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="g card dz">
      <div class="t"><?= ef_icon('warn', '#ea580c') ?> Danger zone</div>
      <?php foreach (['income' => ['Reset income', 'Deletes all income entries'], 'expense' => ['Reset expenses', 'Deletes all expense entries'], 'all' => ['Reset all data', 'Deletes income, expenses and repeating items']] as $type => [$text, $hint]): ?>
      <div class="row"><span><?= e($text) ?><small><?= e($hint) ?></small></span>
        <b><a class="btn o sm" style="color:#b91c1c" href="<?= e($base_url) ?>/reset-data.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"type":"<?= e($type) ?>"}' onclick="return confirm('<?= e($text) ?>? This cannot be undone.')">Reset</a></b></div>
      <?php endforeach; ?>
      <div class="row"><span>Delete account<small>Permanent, cannot be undone</small></span>
        <b><a class="btn o sm" style="color:#b91c1c" href="<?= e($base_url) ?>/auth/delete-account.php">Delete</a></b></div>
    </div>

    <div class="g card">
      <div class="t">More</div>
      <?php if (!empty($user['is_admin'])): ?>
      <div class="row"><span><?= ef_icon('shield') ?> Admin panel<small>Manage users and data</small></span><b><a class="lnk" href="<?= e($base_url) ?>/admin/index.php">Open</a></b></div>
      <?php endif; ?>
      <div class="row"><span><?= ef_icon('lifebuoy') ?> Help &amp; support<small>Ask us a question or report a problem</small></span><b><a class="lnk" href="<?= e($base_url) ?>/support/index.php">Open</a></b></div>
      <div class="row" id="install-row" hidden><span><?= ef_icon('phone') ?> Install app<small>Works offline on your phone or PC</small></span><b><a class="lnk" href="#" id="install-btn">Install</a></b></div>
      <div class="row"><span><?= ef_icon('doc') ?> Full report<small>Export CSV, Excel or PDF</small></span><b><a class="lnk" href="<?= e($base_url) ?>/reports/view-report.php">Open</a></b></div>
      <a class="btn" style="width:100%;margin-top:12px" href="<?= e($base_url) ?>/auth/logout.php" data-post data-csrf="<?= e($csrf) ?>"><?= ef_icon('out') ?> Logout</a>
    </div>
  </div>
</div>
<?php
$js = '<script>(function(){var p=null,r=document.getElementById("install-row"),b=document.getElementById("install-btn");window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();p=e;r.hidden=false});b.addEventListener("click",function(e){e.preventDefault();if(p){p.prompt();p=null;r.hidden=true}});window.addEventListener("appinstalled",function(){r.hidden=true})})()</script>';
ef_page_end(['active' => '', 'scripts' => $js]);
