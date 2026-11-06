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
<?php
$pushOn = isset(ef_modules()['push']) && is_file(__DIR__ . '/../modules/push/lib.php');
if ($pushOn) {
    $pushT = ['turnOn' => t('Turn on phone alerts'), 'turnOff' => t('Turn off on this device'),
        'off' => t('Off on this device. Turn it on to get shared bills, reminders and budget alerts even when ExpenseFlow is closed.'),
        'on' => t('On for this device. You will get shared bills, reminders and budget alerts here.'),
        'denied' => t('Notifications are blocked for this site. Allow them in the browser or phone settings, then try again.'),
        'unsupported' => t('This browser cannot show phone alerts. Use Chrome or Edge on Android or a computer.'),
        'shell' => t('Phone alerts work in the browser (Chrome or Edge). The installed Android and Windows apps show the bell list instead.'),
        'sent' => t('Test sent. It should appear in a few seconds.'), 'notsent' => t('The test could not be delivered. Turn alerts off and on again.'),
        'error' => t('Something went wrong. Please try again.')];
?>
    <div class="g card" id="push-box" data-url="<?= e(rtrim((string) ($GLOBALS['base_url'] ?? ''), '/') . '/push-subscribe.php') ?>" data-t="<?= e(json_encode($pushT, JSON_UNESCAPED_UNICODE)) ?>">
      <div class="row"><span><?= ef_icon('phone') ?> <?= e(t('Phone alerts')) ?><small data-push-msg><?= e(t('Checking…')) ?></small></span></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <button class="btn" type="button" data-push-btn hidden><?= e(t('Turn on phone alerts')) ?></button>
        <button class="btn ghost" type="button" data-push-test hidden><?= e(t('Send a test')) ?></button>
      </div>
    </div>
    <script src="<?= e(ef_asset('assets/push.js')) ?>" defer></script>
<?php } ?>
<?php settings_page_end();
