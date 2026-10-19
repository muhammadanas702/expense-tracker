<?php
require_once __DIR__ . '/auth.php';
admin_need('cat');
require_once __DIR__ . '/../modules/categories/lib.php';
$rows = categories_all($conn);
$usage = categories_usage($conn);
$icons = categories_icons();
$on = count(array_filter($rows, fn($r) => (int) $r['active']));
admin_page_start(['title' => 'Categories', 'sub' => $on . ' of ' . count($rows) . ' available for new expenses', 'active' => 'cat']);
?>
<div class="g card" style="margin-bottom:14px">
  <h3 style="margin:0 0 10px">Add a category</h3>
  <form method="post" action="category-action.php" class="toolbar">
    <?= csrf_field() ?>
    <input class="inp" name="name" maxlength="<?= CATEGORY_NAME_MAX ?>" placeholder="e.g. Gym, Subscriptions" required aria-label="Category name">
    <select class="inp" name="icon" style="max-width:170px" aria-label="Icon"><?php foreach ($icons as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn sm" type="submit" name="do" value="add">Add</button>
  </form>
  <small class="k">Turning a category off or deleting it only hides it for new expenses. Old expenses keep their category. "Other" always stays.</small>
</div>
<div class="g card">
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Category</th><th class="hm">Expenses</th><th>State</th><th></th></tr>
    <?php foreach ($rows as $i => $r): $keep = $r['name'] === CATEGORY_KEEP; ?>
    <tr>
      <td><?= ef_icon($r['icon']) ?> <b><?= e($r['name']) ?></b><small class="dt-m"><?= (int) ($usage[$r['name']] ?? 0) ?> expenses</small></td>
      <td class="hm"><?= (int) ($usage[$r['name']] ?? 0) ?></td>
      <td><span class="tag <?= (int) $r['active'] ? 'tb2' : 'tu' ?>"><?= (int) $r['active'] ? 'On' : 'Off' ?></span></td>
      <td class="nw">
        <form method="post" action="category-action.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn sm o" name="do" value="up" type="submit" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
          <button class="btn sm o" name="do" value="down" type="submit" aria-label="Move down"<?= $i === count($rows) - 1 ? ' disabled' : '' ?>>↓</button>
          <?php if (!$keep): ?>
          <button class="btn sm o" name="do" value="toggle" type="submit"><?= (int) $r['active'] ? 'Turn off' : 'Turn on' ?></button>
          <button class="btn sm o" name="do" value="delete" type="submit" onclick="return confirm('Delete this category? Old expenses keep the name.')">Delete</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php admin_page_end();
