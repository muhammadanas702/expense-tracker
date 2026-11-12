<?php
require_once __DIR__ . '/auth.php';
admin_need('backup');
require_once __DIR__ . '/../modules/backup/lib.php';
require_once __DIR__ . '/../modules/backup/offsite.php';
$off = offsite_status();
$list = backup_list();
admin_page_start(['title' => 'Backups', 'sub' => count($list) . ' saved (the newest ' . BACKUP_KEEP . ' are kept)', 'active' => 'bak']);
?>
<div class="g card" style="margin-bottom:14px">
  <form method="post" action="backup-action.php" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?>
    <button class="btn" type="submit" name="do" value="create">Create backup now</button>
    <small class="k" style="flex:1 1 240px">A backup is a copy of the whole database, including every account and password hash, so keep the downloaded file private. Receipt pictures are not included. To restore, import the file in phpMyAdmin.</small>
  </form>
</div>
<div class="g card" id="offsite" style="margin-bottom:14px;border-left:4px solid <?= $off['state'] === 'ok' ? '#0d9f6e' : ($off['state'] === 'off' ? '#64748b' : '#d97706') ?>">
  <b>Off-site copy (GitHub)</b>
  <small class="k" style="display:block;margin:4px 0 10px">If the free host ever suspends the account, backups kept only on it are lost. This sends the newest backup, <b>encrypted</b>, to a private GitHub repository once a day (when you open the admin overview). The newest <?= (int) OFFSITE_KEEP ?> copies are kept. Open one later with <code>php tools/decrypt-backup.php file.enc</code>.</small>
  <p style="margin:0 0 10px"><b><?= $off['state'] === 'ok' ? 'Working.' : ($off['state'] === 'off' ? 'Switched off.' : 'Needs attention.') ?></b> <?= e($off['text']) ?></p>
  <form method="post" action="offsite-action.php" style="display:grid;gap:10px;max-width:520px">
    <?= csrf_field() ?>
    <label class="k">Private repository (owner/name)
      <input class="inp" type="text" name="repo" value="<?= e($off['config']['repo']) ?>" placeholder="muhammadanas702/expenseflow-backups" autocomplete="off" spellcheck="false"></label>
    <label class="k">GitHub token (fine-grained, only this repository, Contents: read and write)
      <input class="inp" type="password" name="token" placeholder="<?= $off['config']['token'] !== '' ? 'Saved. Leave empty to keep it.' : 'github_pat_...' ?>" autocomplete="new-password"></label>
    <label class="k">Passphrase (8+ characters; without it the copies cannot be opened, so do not lose it)
      <input class="inp" type="password" name="pass" minlength="8" placeholder="<?= $off['config']['pass'] !== '' ? 'Saved. Leave empty to keep it.' : 'Choose a passphrase' ?>" autocomplete="new-password"></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn" type="submit" name="do" value="save">Save and test</button>
      <?php if ($off['state'] !== 'off'): ?>
      <button class="btn o" type="submit" name="do" value="push">Send newest backup now</button>
      <button class="btn o" type="submit" name="do" value="off" onclick="return confirm('Switch off and remove the saved token and passphrase from this server?')">Switch off</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php if (backup_is_stale()): $ag = backup_age_days(); ?>
<div class="g card" style="margin-bottom:14px;border-left:4px solid #d97706"><b>Off-site copy needed.</b>
  <small class="k"><?= $ag === null ? 'No backup exists yet.' : 'The newest backup is ' . (int) $ag . ' days old.' ?> Create one, download it (encrypted is safest) and keep it somewhere other than this server, because a backup that lives only on the host is lost if the host fails.</small></div>
<?php endif; ?>
<div class="g card" style="margin-bottom:14px">
  <b>Encrypted download</b>
  <small class="k" style="display:block;margin:4px 0 8px">Type a passphrase (8+ characters) once, then press "Download encrypted" on a backup below. Without the passphrase nobody can read the file, and it cannot be recovered, so do not lose it. Open it later with <code>php tools/decrypt-backup.php file.enc</code>.</small>
  <input class="inp" type="password" name="pass" id="encpass" minlength="8" autocomplete="new-password" placeholder="Passphrase" style="max-width:320px">
</div>
<div class="g card">
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Backup</th><th class="hm">Size</th><th></th></tr>
    <?php foreach ($list as $b): ?>
    <tr>
      <td><b><?= e(date('d M Y, H:i', $b['time'])) ?></b><small class="dt-m"><?= e(backup_size_label($b['size'])) ?></small></td>
      <td class="hm"><?= e(backup_size_label($b['size'])) ?></td>
      <td class="nw">
        <form method="post" action="backup-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><input type="hidden" name="pass" value="">
          <button class="btn sm o" name="do" value="download" type="submit">Download</button>
          <button class="btn sm o" name="do" value="download_enc" type="submit" onclick="var p=document.getElementById('encpass');if(!p.value||p.value.length<8){alert('Type a passphrase of at least 8 characters first.');return false}this.form.elements.pass.value=p.value">Download encrypted</button>
          <button class="btn sm o" name="do" value="delete" type="submit" onclick="return confirm('Delete this backup?')">Delete</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="3" class="k" style="padding:18px 12px">No backups yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php admin_page_end();
