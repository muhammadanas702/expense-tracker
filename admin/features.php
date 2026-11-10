<?php
/** Features on/off: every optional part of the app with a switch and a plain-words description. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/features/lib.php';

$off = features_off_list(true);
$n = count($off);
admin_page_start(['title' => 'Features on/off', 'sub' => $n ? $n . ' switched off' : 'Everything is on', 'active' => 'feat']);
?>
<style>
.ft-grp{margin-bottom:14px}.ft-grp h3{margin:0 0 4px}
.ft{display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-top:1px solid var(--line,#e2e8f0)}
.ft:first-of-type{border-top:0}
.ft .tx{flex:1;min-width:0}.ft .tx b{font-size:1rem}.ft .tx p{margin:4px 0 0;line-height:1.5}
.ft .wh{display:block;margin-top:6px;font-size:.84rem;color:#64748b}
.ft form{margin:0;flex:none}
.sw{display:inline-flex;align-items:center;gap:10px;border:0;background:none;cursor:pointer;font:inherit;font-weight:700;padding:4px;min-height:44px}
.sw i{position:relative;width:52px;height:30px;border-radius:99px;background:#cbd5e1;transition:background .2s;flex:none}
.sw i:after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform .2s}
.sw.on i{background:#0d9f6e}.sw.on i:after{transform:translateX(22px)}
.sw span{min-width:28px;text-align:left}.sw.on span{color:#0d9f6e}.sw:not(.on) span{color:#b45309}
.ft.isoff .tx b{color:#92400e}
.ft .tag{margin-inline-start:6px}
@media(max-width:560px){.ft{flex-direction:column;gap:6px}.ft form{align-self:flex-start}}
</style>
<div class="g card" style="margin-bottom:14px">
  <b>Something not working properly?</b>
  <p class="mut" style="margin:4px 0 10px">Switch that part <b>off</b> here. People stop seeing it, nothing breaks, and nobody's data is deleted. Switch it <b>on</b> again when it is fixed. Changes work straight away for everyone.</p>
<?php if ($n): ?>
  <form method="post" action="features-action.php" style="margin:0"><?= csrf_field() ?><button class="btn o sm" type="submit" name="do" value="all_on">Turn everything back on</button></form>
<?php else: ?>
  <small class="k">Nothing is switched off right now.</small>
<?php endif; ?>
</div>
<?php foreach (features_catalog() as $group => $list): ?>
<div class="g card ft-grp">
  <h3><?= e($group) ?></h3>
<?php foreach ($list as $id => $f): $on = !in_array($id, $off, true); $parentOff = !empty($f['needs']) && !ef_feature_on($f['needs']); ?>
  <div class="ft<?= $on && !$parentOff ? '' : ' isoff' ?>" id="f-<?= e($id) ?>">
    <div class="tx">
      <b><?= e($f['label']) ?></b><?php if (!$on): ?><span class="tag tx">OFF</span><?php elseif ($parentOff): ?><span class="tag tx">OFF (because <?= e(features_flat()[$f['needs']]['label']) ?> is off)</span><?php endif; ?>
      <p class="mut"><?= e($f['desc']) ?></p>
      <span class="wh"><b>When off:</b> <?= e($f['off']) ?></span>
    </div>
    <form method="post" action="features-action.php">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($id) ?>">
      <button class="sw<?= $on ? ' on' : '' ?>" type="submit" name="do" value="<?= $on ? 'off' : 'on' ?>" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e($f['label']) ?>: <?= $on ? 'on, press to switch off' : 'off, press to switch on' ?>"><i></i><span><?= $on ? 'On' : 'Off' ?></span></button>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<div class="g card"><small class="k">Core parts (login, adding income and expenses, the dashboard, reports, settings) cannot be switched off. If something there is broken, check <a href="health.php">Website health</a>.</small></div>
<?php admin_page_end();
