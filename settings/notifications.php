<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        settings_done('', 'Your session expired. Please try again.');
    }
    $cur = profile_settings($conn, $user_id);
    profile_save_settings($conn, $user_id, ['savings_goal' => (int) $cur['savings_goal'], 'notifications' => !empty($_POST['notifications'])]);
    logAction($conn, $user_id, 'update_profile', 'Updated notifications');
    settings_done('Notification settings saved.');
}
$settings = profile_settings($conn, $user_id);
settings_page_start('notifications', $user);
?>
    <div class="g card">
      <form method="POST">
        <?= csrf_field() ?>
        <label class="row" style="cursor:pointer">
          <span><?= ef_icon('bell') ?> Notifications<small>Alerts for shared bills, settle-ups and replies from support</small></span>
          <b><input class="swc" type="checkbox" name="notifications" value="1"<?= $settings['notifications'] ? ' checked' : '' ?>><i class="sw"></i></b>
        </label>
        <button class="btn" type="submit" style="margin-top:10px">Save</button>
      </form>
    </div>
<?php settings_page_end();
