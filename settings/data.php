<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();
$csrf = csrf_token();
settings_page_start('data', $user);
?>
    <div class="g card">
      <div class="t">Your data</div>
<?php if (ef_feature_on('reports')): ?>
      <div class="row"><span><?= ef_icon('doc') ?> Full report<small>Export CSV, Excel or PDF</small></span><b><a class="lnk" href="<?= e($base_url) ?>/reports/view-report.php">Open</a></b></div>
<?php endif; ?>
    </div>
    <div class="g card dz">
      <div class="t"><?= ef_icon('warn', '#ea580c') ?> Danger zone</div>
      <?php foreach (['income' => ['Reset income', 'Deletes all income entries'], 'expense' => ['Reset expenses', 'Deletes all expense entries'], 'all' => ['Reset all data', 'Deletes income, expenses and repeating items']] as $type => [$text, $hint]): ?>
      <div class="row"><span><?= e($text) ?><small><?= e($hint) ?></small></span>
        <b><a class="btn o sm" style="color:#b91c1c" href="<?= e($base_url) ?>/reset-data.php" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"type":"<?= e($type) ?>"}' onclick="return confirm('<?= e($text) ?>? This cannot be undone.')">Reset</a></b></div>
      <?php endforeach; ?>
      <div class="row"><span>Delete account<small>Permanent, cannot be undone</small></span>
        <b><a class="btn o sm" style="color:#b91c1c" href="<?= e($base_url) ?>/auth/delete-account.php">Delete</a></b></div>
    </div>
<?php settings_page_end();
