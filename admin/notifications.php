<?php
/** Admin > Notifications: see who can receive phone alerts, send a test or an announcement, and control which alerts go out. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/push/nudges.php';
push_ensure_schema($conn);

$webDevices = (int) $conn->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
$webPeople = (int) $conn->query('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions')->fetchColumn();
$appDevices = (int) $conn->query('SELECT COUNT(*) FROM app_devices')->fetchColumn();
$appRecent = (int) $conn->query('SELECT COUNT(*) FROM app_devices WHERE last_poll > NOW() - INTERVAL 2 DAY')->fetchColumn();
$people = (int) $conn->query('SELECT COUNT(*) FROM (SELECT user_id FROM push_subscriptions UNION SELECT user_id FROM app_devices) x')->fetchColumn();
$waiting = (int) $conn->query('SELECT COUNT(*) FROM app_inbox WHERE delivered_at IS NULL AND created_at > NOW() - INTERVAL 3 DAY')->fetchColumn();
$lastRun = (string) $conn->query('SELECT last_seen FROM push_nudges WHERE user_id = 0')->fetchColumn();
$lastRun = $lastRun && $lastRun > '2001-01-01' ? date('d M, H:i', strtotime($lastRun)) : 'not yet';
$cfg = fn(string $k) => push_cfg($conn, $k);
$on = fn(string $k) => push_cfg_on($conn, $k);
$pushFeature = ef_feature_on('push');
$pages = ['' => 'Dashboard (default)', 'shared/index.php' => 'Shared', 'activity.php' => 'Activity', 'reminders.php' => 'Reminders', 'budgets.php' => 'Budgets'];

admin_page_start(['title' => 'Notifications', 'sub' => $pushFeature ? $people . ' people can get alerts' : 'Phone alerts are switched off in Features', 'active' => 'notif']);
?>
<style>
.nt-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.nt-stats b{display:block;font-size:1.5rem;font-weight:800;line-height:1.2}
.nt-row{display:flex;gap:14px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--line,#e2e8f0)}
.nt-row:first-of-type{border-top:0}
.nt-row .ntx{flex:1;min-width:0}.nt-row .ntx b{display:block}.nt-row .ntx small{display:block;margin-top:3px;line-height:1.5}
.nt-row label.ntsw{flex:none;display:inline-flex;align-items:center;gap:8px;min-height:44px;cursor:pointer;font-weight:700}
.nt-row label.ntsw input{-webkit-appearance:none;appearance:none;position:relative;width:52px;height:30px;min-height:0;margin:0;padding:0;border:0;box-shadow:none;border-radius:99px;background:#cbd5e1;cursor:pointer;transition:background .2s;flex:none}
.nt-row label.ntsw input:after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s}
.nt-row label.ntsw input:checked{background:#0d9f6e}.nt-row label.ntsw input:checked:after{transform:translateX(22px)}
.nt-row label.ntsw input:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
.nt-sub{display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap}.nt-sub .inp{width:auto;min-width:0;max-width:150px}
.nt-form{display:grid;gap:12px}.nt-form .fl{margin:0}
.nt-to{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
.nt-to label{display:flex;align-items:center;gap:8px;border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:10px 12px;min-height:44px;cursor:pointer;font-weight:600;text-transform:none;letter-spacing:0;font-size:.95rem;color:inherit}
.nt-to label:has(input:checked){border-color:var(--a1);background:rgba(37,99,235,.07)}
.nt-to input{width:18px;height:18px;margin:0}
@media(max-width:560px){.nt-sub .inp{max-width:none;flex:1}}
</style>

<?php if (!$pushFeature): ?>
<div class="msg err" style="margin-bottom:14px">Phone alerts are switched <b>off</b> in <a href="features.php#f-push">Features on/off</a>, so nothing is sent. Switch them on there first.</div>
<?php endif; ?>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 10px">Who can receive alerts</h3>
  <div class="nt-stats">
    <div><small class="k">People</small><b><?= $people ?></b></div>
    <div><small class="k">Browsers</small><b><?= $webDevices ?></b><small class="k"><?= $webPeople ?> people</small></div>
    <div><small class="k">Android phones</small><b><?= $appDevices ?></b><small class="k"><?= $appRecent ?> checked in (2 days)</small></div>
    <div><small class="k">Waiting for phones</small><b><?= $waiting ?></b><small class="k">alerts not picked up yet</small></div>
    <div><small class="k">Last nudge round</small><b style="font-size:1.05rem"><?= e($lastRun) ?></b></div>
  </div>
  <small class="k" style="display:block;margin-top:10px">Alerts reach a browser at once. The Android app asks the server about every 15 minutes, so phones get them a little later.</small>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 4px">Send a test or an announcement</h3>
  <p class="mut" style="margin:0 0 12px">The message goes to browsers and Android phones, exactly like a real alert.</p>
  <form method="post" action="notifications-action.php" class="nt-form" onsubmit="var a=this.querySelector('[name=to]:checked');if(a&&a.value==='all'){return confirm('Send this to everyone who has alerts on?');}">
    <?= csrf_field() ?><input type="hidden" name="do" value="send">
    <div class="fl"><label>Send to</label>
      <div class="nt-to">
        <label><input type="radio" name="to" value="me" checked> Only me</label>
        <label><input type="radio" name="to" value="user"> One person</label>
        <label><input type="radio" name="to" value="all"> Everyone with alerts on</label>
      </div>
    </div>
    <div class="fl"><label for="nt-who">Person (e-mail or @username, only for "One person")</label><input class="inp" id="nt-who" name="who" maxlength="190" placeholder="name@example.com or @username" autocomplete="off"></div>
    <div class="fl"><label for="nt-title">Title</label><input class="inp" id="nt-title" name="title" maxlength="60" value="ExpenseFlow" required></div>
    <div class="fl"><label for="nt-body">Message</label><textarea class="inp" id="nt-body" name="body" maxlength="180" rows="3" required placeholder="This is a test alert. If you can read it, alerts work."></textarea></div>
    <div class="fl"><label for="nt-url">When tapped, open</label><select class="inp" id="nt-url" name="url"><?php foreach ($pages as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><button class="btn" type="submit">Send</button></div>
  </form>
</div>

<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 4px">Control the alerts</h3>
  <p class="mut" style="margin:0 0 6px">Switch a kind of alert off and it stops for everyone. People can still turn alerts off for themselves in Settings.</p>
  <form method="post" action="notifications-action.php">
    <?= csrf_field() ?><input type="hidden" name="do" value="save">
<?php
$rows = [
    ['push_web', 'Browsers (Chrome, Edge)', 'Alerts to people who allowed notifications in their browser.', ''],
    ['push_app', 'Android app', 'Alerts that the Android app picks up and shows as phone notifications.', ''],
    ['push_events', 'Shared bills and group news', 'A friend adds a shared expense (with your share), a payment, a comment, someone joins or leaves.', ''],
    ['push_reminders', 'Reminders and budgets', 'A bill is due soon, or a budget is almost used up.', ''],
    ['push_debt', 'Debt reminder', 'A gentle message to someone who still owes money. One message per person, not one per friend.', 'debt'],
    ['push_idle', '"We miss you" nudges', 'A short message to someone who has not opened the app for a while. Starts again when they open it.', 'idle'],
];
foreach ($rows as [$k, $label, $desc, $extra]): ?>
    <div class="nt-row">
      <div class="ntx"><b><?= e($label) ?></b><small class="mut"><?= e($desc) ?></small>
<?php if ($extra === 'debt'): ?>
        <div class="nt-sub"><label for="nt-dd">Repeat every</label><input class="inp" id="nt-dd" type="number" name="push_debt_days" min="1" max="30" value="<?= e($cfg('push_debt_days')) ?>"> <span>days</span></div>
<?php elseif ($extra === 'idle'): ?>
        <div class="nt-sub"><label for="nt-id">After this many days away</label><input class="inp" id="nt-id" name="push_idle_days" maxlength="30" value="<?= e($cfg('push_idle_days')) ?>" placeholder="3, 7, 14, 30"></div>
        <small class="k">One message at each number (1 to 4 numbers).</small>
<?php endif; ?>
      </div>
      <label class="ntsw"><input type="checkbox" name="<?= e($k) ?>" value="1"<?= $on($k) ? ' checked' : '' ?>> <span class="k" style="font-weight:700"></span></label>
    </div>
<?php endforeach; ?>
    <div class="nt-row">
      <div class="ntx"><b>Hours for nudges (Pakistan time)</b><small class="mut">Debt reminders and "we miss you" nudges are only sent between these hours, so nobody is woken at night. Real events (a friend adds a bill) are always sent at once.</small>
        <div class="nt-sub"><label for="nt-f">From</label><input class="inp" id="nt-f" type="number" name="push_from" min="0" max="23" value="<?= e($cfg('push_from')) ?>"> <label for="nt-t">to</label><input class="inp" id="nt-t" type="number" name="push_to" min="1" max="24" value="<?= e($cfg('push_to')) ?>"> <span>o'clock (24-hour)</span></div>
      </div>
    </div>
    <div style="margin-top:12px"><button class="btn" type="submit">Save</button></div>
  </form>
</div>
<?php admin_page_end();
