<?php
/** Feature switches: turn an option off (hidden + blocked) when it misbehaves, and on again later. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/features/lib.php';

$cat = features_catalog();
$off = features_off();
$saved = features_saved_off();
$sections = [];
foreach ($cat as $id => $f) {
    $sections[$f['section']][$id] = $f;
}
$nOff = count($off);

admin_page_start(['active' => 'feat', 'title' => 'Features on / off', 'sub' => 'Switch an option off when it is not working, switch it on again when fixed']);
?>
<style>
.ft-note{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;margin-bottom:14px}
.ft-note p{margin:0;line-height:1.5}
.ft-sec{margin:18px 2px 8px;font-weight:700;font-size:.95rem}
.ft-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}
.ft{display:flex;flex-direction:column;gap:8px;padding:14px 16px;min-width:0}
.ft.off{border-inline-start:4px solid #dc2626}
.ft.on{border-inline-start:4px solid #16a34a}
.ft-h{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.ft-h b{font-size:1rem}
.ft p{margin:0;line-height:1.45}
.ft .eff{font-size:.86rem;opacity:.85;line-height:1.4}
.ft form{margin:0;margin-top:auto;padding-top:4px}
.ft .btn{width:100%}
.ft .btn.stop{background:#dc2626;background-image:none;color:#fff}
@media(max-width:420px){.ft-grid{grid-template-columns:1fr}}
</style>
<div class="g card ft-note">
  <?= ef_icon('shield') ?>
  <p><b>How this works.</b> Turning an option <b>off</b> hides it from the menus and blocks its pages, so nobody can open it, even with an old link. <b>No data is deleted.</b> Turn it on again and everything is back. Use this when an option shows an error or behaves badly while you fix it.
  <?php if ($nOff > 0): ?><br><b style="color:#dc2626"><?= (int) $nOff ?> option<?= $nOff === 1 ? ' is' : 's are' ?> off right now.</b><?php else: ?><br>Everything is on right now.<?php endif; ?></p>
</div>
<?php foreach ($sections as $title => $items): ?>
<div class="ft-sec"><?= e($title) ?></div>
<div class="ft-grid">
<?php foreach ($items as $id => $f):
    $isOff = in_array($id, $off, true);
    $byParent = $isOff && !in_array($id, $saved, true); // off only because the option it needs is off
?>
  <div class="g card ft <?= $isOff ? 'off' : 'on' ?>" id="f-<?= e($id) ?>">
    <div class="ft-h"><b><?= e($f['label']) ?></b><span class="tag <?= $isOff ? 'rs' : 'tg' ?>"><?= $isOff ? 'Off' : 'On' ?></span></div>
    <p><?= e($f['desc']) ?></p>
    <p class="eff"><b>If you turn it off:</b> <?= e($f['off']) ?></p>
    <?php if ($byParent): ?>
      <small class="k">It is off because "<?= e($cat[$f['needs']]['label']) ?>" is off. Turn that one on to bring this back.</small>
    <?php else: ?>
    <form method="post" action="features-action.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>">
      <?php if (isset($f['needs']) && !$isOff): ?><small class="k" style="display:block;margin-bottom:6px">Works only while "<?= e($cat[$f['needs']]['label']) ?>" is on.</small><?php endif; ?>
      <button class="btn sm <?= $isOff ? '' : 'stop' ?>" type="submit" name="do" value="<?= $isOff ? 'on' : 'off' ?>"<?= $isOff ? '' : ' onclick="return confirm(' . e(json_encode('Turn off ' . $f['label'] . '? Users will not be able to open it until you turn it on again.')) . ')"' ?>><?= $isOff ? 'Turn on' : 'Turn off' ?></button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php admin_page_end(); ?>
