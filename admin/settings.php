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
  <div class="row"><span>Open for new sign-ups<small>Turn off to stop new accounts. People who already have an account are not affected.</small></span>
    <b><input class="swc" type="checkbox" name="signups" value="1"<?= $s['signups'] !== '0' ? ' checked' : '' ?>><i class="sw"></i></b></div>
  <div class="row"><span>Maintenance mode<small>Everyone except signed-in staff sees a "be right back" page. Use it while you restore a backup or fix something.</small></span>
    <b><input class="swc" type="checkbox" name="maintenance" value="1"<?= $s['maintenance'] === '1' ? ' checked' : '' ?>><i class="sw"></i></b></div>
  <div class="fl" style="margin-top:12px"><label for="maintenance_msg">Maintenance message</label>
    <textarea class="inp" id="maintenance_msg" name="maintenance_msg" rows="3" maxlength="<?= SETTINGS_MESSAGE_MAX ?>"><?= e($s['maintenance_msg']) ?></textarea></div>
  <button class="btn" type="submit">Save</button>
</form>
<?php admin_page_end();
