<?php
require_once __DIR__ . '/auth.php';
admin_need('support');
require_once __DIR__ . '/../modules/support/lib.php';
support_ensure_schema($conn);

$status = isset(SUPPORT_STATUSES[$_GET['s'] ?? '']) ? $_GET['s'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
[$rows, $total, $page] = support_inbox($conn, $status, $q, max(1, (int) ($_GET['p'] ?? 1)), 25);
$pages = max(1, (int) ceil($total / 25));
$counts = support_counts($conn);
$url = fn(array $o = []) => 'support.php?' . http_build_query(array_filter(array_merge(['s' => $status, 'q' => $q, 'p' => $page > 1 ? $page : ''], $o), fn($v) => $v !== '' && $v !== null));

admin_page_start(['title' => 'Support', 'sub' => $counts['open'] . ' waiting for an answer', 'active' => 'sup']);
?>
<div class="g card">
  <form method="get" class="toolbar">
    <?php if ($status !== ''): ?><input type="hidden" name="s" value="<?= e($status) ?>"><?php endif; ?>
    <input class="inp" type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject, name or email" maxlength="60">
    <button class="btn sm" type="submit">Search</button>
    <a class="btn sm o" href="support.php">Clear</a>
  </form>
  <div class="chips" style="margin-bottom:12px">
    <a class="chip<?= $status === '' ? ' on' : '' ?>" href="<?= e($url(['s' => '', 'p' => ''])) ?>">All (<?= array_sum($counts) ?>)</a>
    <?php foreach (SUPPORT_STATUSES as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e($url(['s' => $k, 'p' => ''])) ?>"><?= e($l) ?> (<?= $counts[$k] ?>)</a><?php endforeach; ?>
  </div>
  <div class="tbl-wrap"><table class="tbl fixm">
    <tr><th>Request</th><th class="hm">From</th><th class="hm">Topic</th><th>Status</th><th class="hm">Updated</th><th class="nw"></th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="lnk" href="ticket.php?id=<?= (int) $r['id'] ?>"><?= e($r['subject']) ?></a><small class="dt-m"><?= e($r['user_name'] ?? 'Deleted user') ?> · <?= e(support_ago((string) $r['updated_at'])) ?></small></td>
      <td class="hm"><?= e($r['user_name'] ?? 'Deleted user') ?></td>
      <td class="hm"><?= e(SUPPORT_CATEGORIES[$r['category']] ?? 'Other') ?></td>
      <td><?= support_pill($r['status']) ?></td>
      <td class="hm nw"><?= e(support_ago((string) $r['updated_at'])) ?></td>
      <td class="nw"><a class="btn sm" href="ticket.php?id=<?= (int) $r['id'] ?>#reply"><?= $r['status'] === 'closed' ? 'View' : 'Reply' ?></a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="k" style="padding:18px 12px">No requests here.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="chips" style="margin-top:12px;justify-content:center">
    <?php if ($page > 1): ?><a class="chip" href="<?= e($url(['p' => $page - 1])) ?>">← Newer</a><?php endif; ?>
    <span class="chip">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="chip" href="<?= e($url(['p' => $page + 1])) ?>">Older →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php admin_page_end();
