<?php
// Profile & Settings hub: who you are, then one card per section (each section is its own page under settings/).
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();
$csrf = csrf_token();
ef_page_start(['title' => 'Profile & Settings', 'sub' => 'Account, security and preferences', 'neutral' => true, 'active' => 'profile', 'user' => $user]);
?>
<div class="g card phub">
  <i class="av" style="width:60px;height:60px;font-size:1.2rem"><?= e(ef_initials($user['name'])) ?></i>
  <div><b style="font-size:1.1rem"><?= e($user['name']) ?></b><div class="k"><?= e($user['email']) ?></div></div>
  <a class="btn o sm" href="<?= e($base_url) ?>/settings/account.php"><?= ef_icon('pen') ?> Edit profile</a>
</div>
<div class="shub">
<?php foreach (settings_sections() as $k => [$ic, $t, $d, $f]): ?>
  <a class="g card scard" href="<?= e($base_url . '/' . $f) ?>"><i class="sci"><?= ef_icon($ic) ?></i><span><b><?= e($t) ?></b><small><?= e($d) ?></small></span><?= ef_icon('chev') ?></a>
<?php endforeach; ?>
  <?php if (ef_feature_on('support')): ?>
  <a class="g card scard" href="<?= e($base_url) ?>/support/index.php"><i class="sci"><?= ef_icon('lifebuoy') ?></i><span><b>Help &amp; support</b><small>Ask a question or report a problem</small></span><?= ef_icon('chev') ?></a>
  <?php endif; ?>
<?php if (!empty($user['is_admin'])): ?>
  <a class="g card scard" href="<?= e($base_url) ?>/admin/index.php"><i class="sci"><?= ef_icon('shield') ?></i><span><b>Admin panel</b><small>Manage users and data</small></span><?= ef_icon('chev') ?></a>
<?php endif; ?>
</div>
<a class="btn o" style="margin-top:14px" href="<?= e($base_url) ?>/auth/logout.php" data-post data-csrf="<?= e($csrf) ?>"><?= ef_icon('out') ?> Log out</a>
<?php ef_page_end(['active' => '']);
