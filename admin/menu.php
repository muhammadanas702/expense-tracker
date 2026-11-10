<?php
/** Every admin page the signed-in staff member may use (the phone bottom bar only has room for a few). */
require_once __DIR__ . '/auth.php';
$menu = admin_visible_menu($conn, $admin['role']);
admin_page_start(['title' => 'All pages', 'sub' => 'Everything you can open', 'active' => 'more']);
foreach ($menu as $section => $items): ?>
<div class="g card" style="margin-bottom:12px">
  <b class="k" style="text-transform:uppercase;font-size:.75rem;letter-spacing:.1em"><?= e($section) ?></b>
  <small class="k" style="display:block;margin:2px 0 4px"><?= e(admin_section_info()[$section] ?? '') ?></small>
  <?php foreach ($items as $i): ?>
  <a class="nv" style="margin-top:6px;height:auto;align-items:flex-start;padding-block:10px" href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><span><?= e($i[3]) ?><small class="k" style="display:block;font-weight:400;line-height:1.4"><?= e($i[5] ?? '') ?></small></span></a>
  <?php endforeach; ?>
</div>
<?php endforeach;
admin_page_end();
