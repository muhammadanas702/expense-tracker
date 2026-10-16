<?php
// Opened from an invite link. Shows what you are joining; joining itself is a POST (shared/action.php).
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';

$code = (string) ($_GET['code'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $code)) {
    http_response_code(404);
    exit('This invite link is not valid.');
}
if (!isset($_SESSION['user_id'])) {
    // Remember the link: login.php sends the user back here after signing in.
    $_SESSION['pending_join'] = $code;
    header('Location: ' . $base_url . '/auth/login.php');
    exit();
}
require_once __DIR__ . '/../modules/shared/boot.php';

$q = $conn->prepare('SELECT g.id, g.name, g.icon, (SELECT COUNT(*) FROM shared_members x WHERE x.group_id = g.id) AS n,
                            (SELECT u.name FROM users u WHERE u.id = g.owner_id) AS owner_name
                     FROM shared_groups g WHERE g.invite_code = ?');
$q->execute([$code]);
$g = $q->fetch(PDO::FETCH_ASSOC);
if (!$g) {
    $_SESSION['error'] = 'This invite link is not valid any more.';
    shared_back('shared/groups.php');
}
if (shared_group_for($conn, (int) $g['id'], $user_id)) {
    shared_back('shared/group.php?id=' . (int) $g['id']);
}

ef_page_start(['title' => 'Join group', 'sub' => 'You were invited', 'mode' => 's', 'active' => 'grp', 'user' => $user]);
?>
<div class="g card" style="max-width:480px;margin:0 auto;text-align:center;padding:26px">
  <div class="gi" style="margin:0 auto 12px"><?= ef_icon(shared_icon_key($g['icon']), '#1d4ed8') ?></div>
  <b style="font-size:1.2rem"><?= e($g['name']) ?></b>
  <p class="k" style="margin:8px 0 18px"><?= e((string) $g['owner_name']) ?> invited you · <?= (int) $g['n'] ?> member<?= (int) $g['n'] === 1 ? '' : 's' ?></p>
  <form method="POST" action="<?= e(ef_url('shared/action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="join"><input type="hidden" name="code" value="<?= e($code) ?>">
    <button class="btn" type="submit" style="width:100%;padding:14px">Join group</button>
  </form>
  <a class="lnk" style="display:block;margin-top:14px" href="<?= e(ef_url('shared/groups.php')) ?>">Not now</a>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'grp']);
