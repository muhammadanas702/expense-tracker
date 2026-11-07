<?php
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/settings/lib.php';
settings_ensure_schema($conn);
$s = settings_all($conn, true);
$old = $_SESSION['settings_old'] ?? null;
unset($_SESSION['settings_old']);
if (is_array($old)) {
    $s = array_merge($s, $old);
}
admin_page_start(['title' => 'Settings', 'sub' => $s['maintenance'] === '1' ? 'Maintenance mode is ON' : 'System settings', 'active' => 'set']);
?>
<form method="post" action="settings-action.php" class="g card">
  <?= csrf_field() ?>
  <label class="row" style="cursor:pointer"><span>Open for new sign-ups<small>Turn off to stop new accounts. People who already have an account are not affected.</small></span>
    <b><input class="swc" type="checkbox" name="signups" value="1"<?= $s['signups'] !== '0' ? ' checked' : '' ?>><i class="sw"></i></b></label>
  <label class="row" style="cursor:pointer"><span>Maintenance mode<small>Everyone except signed-in staff sees a "be right back" page. Use it while you restore a backup or fix something.</small></span>
    <b><input class="swc" type="checkbox" name="maintenance" value="1"<?= $s['maintenance'] === '1' ? ' checked' : '' ?>><i class="sw"></i></b></label>
  <div class="fl" style="margin-top:12px"><label for="maintenance_msg">Maintenance message</label>
    <textarea class="inp" id="maintenance_msg" name="maintenance_msg" rows="3" maxlength="<?= SETTINGS_MESSAGE_MAX ?>"><?= e($s['maintenance_msg']) ?></textarea></div>
  <div class="t" style="margin:22px 0 4px">Apps &amp; contact</div>
  <p class="mut" style="margin:0 0 8px">Where the "Get the app" buttons and the contact page point. Change them here when a new release or listing appears; no code change or deploy needed.</p>
  <div class="fl"><label for="link_android">Android download link</label><input class="inp" id="link_android" name="link_android" type="url" maxlength="255" value="<?= e($s['link_android']) ?>"></div>
  <div class="fl"><label for="link_windows">Windows download link</label><input class="inp" id="link_windows" name="link_windows" type="url" maxlength="255" value="<?= e($s['link_windows']) ?>"></div>
  <div class="fl"><label for="link_store">Microsoft Store link <small>(leave empty to hide the button)</small></label><input class="inp" id="link_store" name="link_store" type="url" maxlength="255" placeholder="https://apps.microsoft.com/detail/..." value="<?= e($s['link_store']) ?>"></div>
  <div class="fl"><label for="contact_email">Contact e-mail</label><input class="inp" id="contact_email" name="contact_email" type="email" maxlength="120" value="<?= e($s['contact_email']) ?>"></div>
  <button class="btn" type="submit">Save</button>
</form>
<div class="g card" id="https-card" style="margin-top:14px">
  <b>Force HTTPS</b>
  <small class="k" style="display:block;margin:4px 0 10px">Sends every <code>http://</code> address to <code>https://</code>. A sign-in page served over plain http is one of the things Google's Safe Browsing warning looks at, so turn this on once https works for you. <?= settings_force_https_on() ? 'It is <b>on</b> now.' : 'It is <b>off</b> now.' ?> If the site ever shows "too many redirects", delete <code>storage/force-https.on</code> with the host's file manager.</small>
  <form method="post" action="https-action.php"><?= csrf_field() ?>
    <?php if (settings_force_https_on()): ?><button class="btn o" type="submit" name="do" value="off">Turn off</button>
    <?php else: ?><button class="btn" type="submit" name="do" value="on"<?= settings_is_https() ? '' : ' disabled' ?>>Turn on</button><?= settings_is_https() ? '' : ' <small class="k">Open this page through https:// to enable it.</small>' ?><?php endif; ?>
  </form>
</div>
<?php admin_page_end();
