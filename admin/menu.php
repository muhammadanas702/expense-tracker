<?php
/** Every admin page the signed-in staff member may use (the phone bottom bar only has room for a few). */
require_once __DIR__ . '/auth.php';
$menu = admin_visible_menu($conn, $admin['role']);
admin_page_start(['title' => 'All pages', 'sub' => 'Everything you can open', 'active' => 'more']);
foreach ($menu as $section => $items): ?>
<div class="g card" style="margin-bottom:12px">
  <b class="k" style="text-transform:uppercase;font-size:.7rem;letter-spacing:.1em"><?= e($section) ?></b>
  <?php foreach ($items as $i): ?>
  <a class="nv" style="margin-top:6px" href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><?= e($i[3]) ?></a>
  <?php endforeach; ?>
</div>
<?php endforeach;
admin_page_end();
