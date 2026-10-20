<?php
require_once __DIR__ . '/auth.php';
admin_need('backup');
require_once __DIR__ . '/../modules/backup/lib.php';
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
<div class="g card">
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Backup</th><th class="hm">Size</th><th></th></tr>
    <?php foreach ($list as $b): ?>
    <tr>
      <td><b><?= e(date('d M Y, H:i', $b['time'])) ?></b><small class="dt-m"><?= e(backup_size_label($b['size'])) ?></small></td>
      <td class="hm"><?= e(backup_size_label($b['size'])) ?></td>
      <td class="nw">
        <form method="post" action="backup-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>">
          <button class="btn sm o" name="do" value="download" type="submit">Download</button>
          <button class="btn sm o" name="do" value="delete" type="submit" onclick="return confirm('Delete this backup?')">Delete</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="3" class="k" style="padding:18px 12px">No backups yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php admin_page_end();
