<?php
/** Shared search page: read-only. Logic lives in modules/shared/search.php. */
require_once __DIR__ . '/../modules/shared/boot.php';
require_once __DIR__ . '/../modules/shared/search.php';
$f = shared_search_filters($_GET);
$searched = shared_search_active($f);
$groups = shared_groups_of($conn, $user_id);
$res = ['rows' => [], 'count' => 0];
$loadError = false;
try {
    $res = shared_search_run($conn, $user_id, $f); // no filter = the newest entries
} catch (Throwable $e) {
    error_log('ExpenseFlow shared search: ' . $e->getMessage());
    $loadError = true;
}
ef_page_start(['title' => 'Search', 'sub' => 'Find a shared expense or payment', 'mode' => 's', 'active' => 'sfind', 'user' => $user]);
?>
<div class="sp">
  <?php if ($loadError): ?><div class="msg err" role="alert"><?= e(t('Search could not load right now. Please try again in a moment.')) ?></div><?php endif; ?>
  <form method="GET" autocomplete="off" role="search">
    <div class="sp-bar g">
      <?= ef_icon('search') ?>
      <input type="search" name="q" maxlength="60" value="<?= e($f['q']) ?>" placeholder="<?= e(t('Title, note, person or group')) ?>" aria-label="<?= e(t('Search shared')) ?>">
      <button class="btn sm" type="submit"><?= e(t('Search')) ?></button>
    </div>
    <?php $open = ($f['group'] > 0 || $f['type'] !== 'all' || $f['from'] !== '' || $f['to'] !== ''); ?>
    <details class="sp-more"<?= $open ? ' open' : '' ?>>
      <summary><?= e(t('Filters')) ?></summary>
      <div class="sp-grid g">
        <div class="fl"><label for="sgrp"><?= e(t('Group')) ?></label>
          <select class="inp" id="sgrp" name="group"><option value="0"><?= e(t('All groups')) ?></option>
            <?php foreach ($groups as $gr): ?><option value="<?= (int) $gr['id'] ?>"<?= $f['group'] === (int) $gr['id'] ? ' selected' : '' ?>><?= e($gr['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="fl"><label for="stp"><?= e(t('Type')) ?></label>
          <select class="inp" id="stp" name="type">
            <option value="all"<?= $f['type'] === 'all' ? ' selected' : '' ?>><?= e(t('Expenses and payments')) ?></option>
            <option value="expense"<?= $f['type'] === 'expense' ? ' selected' : '' ?>><?= e(t('Expenses only')) ?></option>
            <option value="settlement"<?= $f['type'] === 'settlement' ? ' selected' : '' ?>><?= e(t('Settle-up payments only')) ?></option>
          </select></div>
        <div class="fl"><label for="sfr"><?= e(t('From')) ?></label><input class="inp" id="sfr" type="date" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="fl"><label for="sto"><?= e(t('To')) ?></label><input class="inp" id="sto" type="date" name="to" value="<?= e($f['to']) ?>"></div>
      </div>
    </details>
  </form>
  <?php if (!$loadError): ?>
  <?php shared_panel($searched ? ($res['count'] === 1 ? t('1 result') : t('%d results', (int) $res['count'])) : 'Latest entries', 'search', $searched ? 'Newest first' : 'Everything you can see, newest first'); ?>
    <?php if ($searched): ?><a class="btn sm o sp-clear" href="<?= e(ef_url('shared/search.php')) ?>"><?= e(t('Clear search')) ?></a><?php endif; ?>
    <?php if (!$res['rows']): ?><p style="color:var(--mu)"><?= e(t($searched ? 'Nothing matches. Try fewer filters or a shorter word.' : 'No shared entries yet. Add a shared expense and it will show up here.')) ?></p><?php endif; ?>
    <?php foreach ($res['rows'] as $r): $isExp = $r['kind'] === 'expense'; ?>
      <a class="row srow" data-kind="<?= e($r['kind']) ?>" href="<?= e(ef_url('shared/group.php?id=' . (int) $r['group_id'])) ?>" style="text-decoration:none;color:inherit">
        <span><?= e($r['title']) ?>
          <small><?= e(date('d M Y', strtotime($r['d']))) ?> · <?= e($r['group_name']) ?><?= $isExp && $r['who'] ? ' · paid by ' . e($r['who']) : '' ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></small></span>
        <b><?= shared_money((int) $r['amount_minor'], $r['currency']) ?></b>
      </a>
    <?php endforeach; ?>
    <?php if ($res['count'] > count($res['rows'])): ?><p style="color:var(--mu);font-size:.85rem;margin-top:8px"><?= e(t('Showing the newest %d. Add a filter to narrow it down.', (int) count($res['rows']))) ?></p><?php endif; ?>
  <?php shared_panel_end(); ?>
  <?php endif; ?>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'sfind']);
