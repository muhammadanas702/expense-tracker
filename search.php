<?php
/** Search page: read-only. Logic lives in modules/search/lib.php. */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/layout.php';
secure_session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules.php';
if (!isset(ef_modules()['search']) || !is_file(__DIR__ . '/modules/search/lib.php')) {
    http_response_code(404);
    exit('Search is switched off.');
}
require_once __DIR__ . '/modules/search/lib.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
$uid = (int) $_SESSION['user_id'];
$u = $conn->prepare('SELECT name, is_admin FROM users WHERE id = ?');
$u->execute([$uid]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
$cats = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book', 'Other' => 'more'];
if (is_file(__DIR__ . '/modules/categories/lib.php')) {
    require_once __DIR__ . '/modules/categories/lib.php';
    $cats = categories_active($conn);
}
$f = search_filters($_GET);
$searched = search_active($f);
$res = ['rows' => [], 'count' => 0, 'income_pkr' => 0.0, 'expense_pkr' => 0.0];
$myTags = [];
$loadError = '';
try {
    $myTags = search_user_tags($conn, $uid);
    $res = search_run($conn, $uid, $f); // no filter = the newest entries, so the page is never empty
} catch (Throwable $e) {
    error_log('ExpenseFlow search page: ' . $e->getMessage());
    $loadError = !empty($user['is_admin']) ? $e->getMessage() : 'unavailable';
}
ef_page_start(['title' => 'Search', 'sub' => 'Find any income or expense', 'mode' => 'p', 'active' => 'find', 'user' => $user]);
?>
    <div class="sp">
      <?php if ($loadError !== ''): ?><div class="msg err" role="alert">Search could not load right now. Please try again in a moment.<?= $loadError !== 'unavailable' ? ' (Admin detail: ' . e($loadError) . ')' : '' ?></div><?php endif; ?>
      <form method="GET" autocomplete="off" id="sform" role="search">
        <div class="sp-bar g">
          <?= ef_icon('search') ?>
          <input id="sq" type="search" name="q" maxlength="60" value="<?= e($f['q']) ?>" placeholder="Title, note, category or tag" aria-label="Search">
          <button class="btn sm" type="submit">Search</button>
        </div>
        <?php $open = ($f['type'] !== 'all' || $f['category'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['min'] !== '' || $f['max'] !== ''); ?>
        <details class="sp-more"<?= $open ? ' open' : '' ?>>
          <summary>Filters</summary>
          <input type="hidden" name="tag" value="<?= e($f['tag']) ?>">
          <div class="sp-grid g">
            <div class="fl"><label for="stype">Type</label>
              <select class="inp" id="stype" name="type">
                <option value="all"<?= $f['type'] === 'all' ? ' selected' : '' ?>>Income and expenses</option>
                <option value="expense"<?= $f['type'] === 'expense' ? ' selected' : '' ?>>Expenses only</option>
                <option value="income"<?= $f['type'] === 'income' ? ' selected' : '' ?>>Income only</option>
              </select></div>
            <div class="fl"><label for="scat">Category</label>
              <select class="inp" id="scat" name="category"><option value="">Any</option>
                <?php foreach (array_keys($cats) as $c): ?><option value="<?= e($c) ?>"<?= $f['category'] === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
              </select></div>
            <div class="fl"><label for="sfrom">From</label><input class="inp" id="sfrom" type="date" name="from" value="<?= e($f['from']) ?>"></div>
            <div class="fl"><label for="sto">To</label><input class="inp" id="sto" type="date" name="to" value="<?= e($f['to']) ?>"></div>
            <div class="fl"><label for="smin">Amount from (PKR)</label><input class="inp" id="smin" type="number" step="0.01" min="0" name="min" value="<?= e($f['min']) ?>"></div>
            <div class="fl"><label for="smax">Amount up to (PKR)</label><input class="inp" id="smax" type="number" step="0.01" min="0" name="max" value="<?= e($f['max']) ?>"></div>
          </div>
        </details>
      </form>
      <?php if ($myTags): ?>
      <div class="chips sp-tags">
        <?php foreach ($myTags as $t): ?><a class="chip tag-chip<?= $f['tag'] === $t ? ' on' : '' ?>" href="<?= e($base_url) ?>/search.php?tag=<?= e(rawurlencode($t)) ?>">#<?= e($t) ?></a><?php endforeach; ?>
        <?php if ($searched): ?><a class="chip" href="<?= e($base_url) ?>/search.php">Clear</a><?php endif; ?>
      </div>
      <?php elseif ($searched): ?><div class="chips sp-tags"><a class="chip" href="<?= e($base_url) ?>/search.php">Clear</a></div><?php endif; ?>
      <?php if ($loadError === ''): ?>
      <div class="card" id="results" style="margin-top:14px">
        <div class="t"><?php if ($searched): ?><?= (int) $res['count'] ?> result<?= $res['count'] === 1 ? '' : 's' ?><?php else: ?>Latest entries<?php endif; ?>
          <?php if ($res['count']): ?><small style="font-weight:500;color:var(--mu)">income PKR <?= e(number_format($res['income_pkr'], 2)) ?> · expenses PKR <?= e(number_format($res['expense_pkr'], 2)) ?></small><?php endif; ?></div>
        <?php if (!$res['rows']): ?><p style="color:var(--mu)"><?= $searched ? 'Nothing matches. Try fewer filters or a shorter word.' : 'No entries yet. Add an income or expense and it will show up here.' ?></p><?php endif; ?>
        <?php foreach ($res['rows'] as $r): $isExp = $r['kind'] === 'expense'; ?>
          <a class="row srow" data-kind="<?= e($r['kind']) ?>" href="<?= e($base_url) ?>/<?= $isExp ? 'edit-expense' : 'edit-income' ?>.php?id=<?= (int) $r['id'] ?>" style="text-decoration:none;color:inherit">
            <span><?= e($r['title']) ?>
              <small><?= e(date('d M Y', strtotime($r['transaction_date']))) ?><?= $isExp && $r['category'] ? ' · ' . e($r['category']) : '' ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?>
              <?php foreach ($r['tags'] as $t): ?> <span style="color:var(--h2)">#<?= e($t) ?></span><?php endforeach; ?></small></span>
            <b style="color:<?= $isExp ? 'var(--exp)' : 'var(--inc)' ?>"><?= $isExp ? '−' : '+' ?><?= e($r['currency']) ?> <?= e(number_format((float) $r['amount'], 2)) ?></b>
          </a>
        <?php endforeach; ?>
        <?php if ($res['count'] > count($res['rows'])): ?><p style="color:var(--mu);font-size:.85rem;margin-top:8px">Showing the newest <?= (int) count($res['rows']) ?>. Add a filter to narrow it down.</p><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
<?php ef_page_end(['active' => 'find']);
