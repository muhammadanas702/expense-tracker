<?php
require_once __DIR__ . '/auth.php';
admin_need('bc');
require_once __DIR__ . '/../modules/announce/lib.php';
announce_ensure_schema($conn);

$edit = null;
if (isset($_GET['edit'])) {
    $edit = announce_get($conn, (int) $_GET['edit']);
}
$old = $_SESSION['announce_old'] ?? null;
unset($_SESSION['announce_old']);
if (is_array($old)) {
    $edit = array_merge($edit ?? ['id' => 0], $old);
}
$rows = announce_list($conn);
$live = count(array_filter($rows, fn($r) => $r['state'] === 'live'));
$pill = ['live' => ['Live', 'tb2'], 'scheduled' => ['Scheduled', 'rs'], 'ended' => ['Ended', 'tu'], 'off' => ['Off', 'tu']];
$val = fn(string $k) => (string) ($edit[$k] ?? '');
$dt = fn(?string $v) => ($v && strtotime($v)) ? date('Y-m-d\TH:i', strtotime($v)) : '';

admin_page_start(['title' => 'Announcements', 'sub' => $live . ' live now', 'active' => 'ann']);
?>
<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 10px"><?= !empty($edit['id']) ? 'Edit announcement' : 'New announcement' ?></h3>
  <form method="post" action="announcement-action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div class="fl"><label for="title">Title</label><input class="inp" id="title" name="title" maxlength="<?= ANNOUNCE_TITLE_MAX ?>" required value="<?= e($val('title')) ?>"></div>
    <div class="fl"><label for="body">Message (optional)</label><textarea class="inp" id="body" name="body" rows="3" maxlength="<?= ANNOUNCE_BODY_MAX ?>"><?= e($val('body')) ?></textarea></div>
    <div class="fl"><label for="level">Style</label><select class="inp" id="level" name="level"><?php foreach (ANNOUNCE_LEVELS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($edit['level'] ?? 'info') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="fl"><label for="starts_at">Show from (optional)</label><input class="inp" type="datetime-local" id="starts_at" name="starts_at" value="<?= e($dt($edit['starts_at'] ?? null)) ?>"></div>
    <div class="fl"><label for="ends_at">Hide after (optional)</label><input class="inp" type="datetime-local" id="ends_at" name="ends_at" value="<?= e($dt($edit['ends_at'] ?? null)) ?>"></div>
    <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="checkbox" name="active" value="1"<?= !$edit || !empty($edit['active']) ? ' checked' : '' ?>> Active</label>
    <small class="k" style="display:block;margin-bottom:10px">Everyone who is signed in sees it as a banner until they close it. Times are server time. Never put private details here.</small>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><button class="btn" type="submit" name="do" value="save">Save</button><?php if ($edit): ?><a class="btn o" href="announcements.php">Cancel</a><?php endif; ?></div>
  </form>
</div>

<div class="g card">
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Announcement</th><th>State</th><th class="hm">Closed by</th><th></th></tr>
    <?php foreach ($rows as $r): [$pl, $pc] = $pill[$r['state']]; ?>
    <tr>
      <td><b><?= e($r['title']) ?></b><small class="dt-m"><?= e(ANNOUNCE_LEVELS[$r['level']] ?? 'Information') ?></small></td>
      <td><span class="tag <?= e($pc) ?>"><?= e($pl) ?></span></td>
      <td class="hm"><?= (int) $r['dismissed'] ?> users</td>
      <td class="nw">
        <a class="btn sm o" href="announcements.php?edit=<?= (int) $r['id'] ?>">Edit</a>
        <form method="post" action="announcement-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn sm o" name="do" value="toggle" type="submit"><?= (int) $r['active'] ? 'Turn off' : 'Turn on' ?></button>
          <button class="btn sm o" name="do" value="delete" type="submit" onclick="return confirm('Delete this announcement?')">Delete</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="k" style="padding:18px 12px">No announcements yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php admin_page_end();
