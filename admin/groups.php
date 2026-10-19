<?php
require_once __DIR__ . '/auth.php';
admin_need('grp');
require_once __DIR__ . '/../modules/groupsadmin/lib.php';

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 60);
$sorts = ['new' => 'Newest', 'old' => 'Oldest', 'big' => 'Most expenses', 'name' => 'Name'];
$sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
$page = max(1, (int) ($_GET['p'] ?? 1));
[$rows, $total] = groupsadmin_list($conn, $q, $sort, $page);
$pages = max(1, (int) ceil($total / GROUPSADMIN_PER_PAGE));
$all = groupsadmin_totals($conn);
$url = fn(array $o = []) => 'groups.php?' . http_build_query(array_filter(array_merge(['q' => $q, 'sort' => $sort === 'new' ? '' : $sort, 'p' => $page > 1 ? $page : ''], $o), fn($v) => $v !== '' && $v !== null));

admin_page_start(['title' => 'Groups', 'sub' => $all['groups'] . ' groups · ' . $all['expenses'] . ' shared expenses', 'active' => 'grp']);
?>
<div class="g card">
  <form method="get" class="toolbar">
    <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Search group, owner name or email" maxlength="60">
    <select class="inp" name="sort" style="max-width:170px" aria-label="Sort"><?php foreach ($sorts as $k => $l): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn sm" type="submit">Apply</button>
    <a class="btn sm o" href="groups.php">Clear</a>
  </form>
  <small class="k" style="display:block;margin-bottom:10px">Staff see only counts, never the expenses or who owes what. Delete a group only for abuse or on the owner's request: it removes the expenses, payments, comments and receipts for everyone.</small>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Group</th><th class="hm">Owner</th><th class="hm">Members</th><th class="hm">Expenses</th><th class="hm">Created</th><th></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><b><?= e($r['name']) ?></b><small class="dt-m"><?= e($r['owner_name'] ?? 'Deleted user') ?> · <?= (int) $r['members'] ?> members · <?= (int) $r['expenses'] ?> expenses</small></td>
      <td class="hm"><?= e($r['owner_name'] ?? 'Deleted user') ?></td>
      <td class="hm"><?= (int) $r['members'] ?></td>
      <td class="hm"><?= (int) $r['expenses'] ?></td>
      <td class="hm nw"><?= e(date('d M Y', strtotime((string) $r['created_at']))) ?></td>
      <td class="nw">
        <form method="post" action="group-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn sm o" name="do" value="delete" type="submit" onclick="return confirm('Delete this group and everything in it for all members? This cannot be undone.')">Delete</button></form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="k" style="padding:18px 12px">No groups found.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="chips" style="margin-top:12px;justify-content:center">
    <?php if ($page > 1): ?><a class="chip" href="<?= e($url(['p' => $page - 1])) ?>">← Previous</a><?php endif; ?>
    <span class="chip">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="chip" href="<?= e($url(['p' => $page + 1])) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_page_end();
