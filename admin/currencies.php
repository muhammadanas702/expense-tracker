<?php
require_once __DIR__ . '/auth.php';
admin_need('cur');
require_once __DIR__ . '/../modules/currencies/lib.php';
currencies_ensure_schema($conn);
$hidden = currencies_hidden_codes($conn);
$usage = currencies_usage($conn);
[$rates, $fallback] = currencies_rates();
$list = app_currencies();
$shown = count($list) - count($hidden);
admin_page_start(['title' => 'Currencies', 'sub' => $shown . ' of ' . count($list) . ' in the pick lists', 'active' => 'cur']);
?>
<div class="g card" style="margin-bottom:14px">
  <b>Exchange rates:</b> <?= $fallback ? '<span class="tag rs">Using saved or built-in rates</span>' : '<span class="tag tb2">Live</span>' ?>
  <small class="k" style="display:block;margin-top:6px">Rates come from a free rate service and are refreshed about every hour. If the service is down, the last saved rates are used. Hiding a currency only removes it from the pick lists for new entries; old entries keep it. PKR and USD always stay.</small>
</div>
<form method="post" action="currency-action.php" class="g card">
  <?= csrf_field() ?>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Currency</th><th class="hm">Per 1 USD</th><th class="hm">Entries</th><th>Show</th></tr>
    <?php foreach ($list as $code => [$flag, $name, $group]): $lock = in_array($code, CURRENCY_LOCKED, true); ?>
    <tr>
      <td><?= e($flag) ?> <b><?= e($code) ?></b> <span class="k"><?= e($name) ?></span><small class="dt-m"><?= (int) ($usage[$code] ?? 0) ?> entries</small></td>
      <td class="hm"><?= isset($rates[$code]) ? e(rtrim(rtrim(number_format((float) $rates[$code], 4, '.', ''), '0'), '.')) : '–' ?></td>
      <td class="hm"><?= (int) ($usage[$code] ?? 0) ?></td>
      <td class="nw"><label><input type="checkbox" name="show[]" value="<?= e($code) ?>"<?= empty($hidden[$code]) ? ' checked' : '' ?><?= $lock ? ' disabled' : '' ?>> <?= $lock ? 'Always' : 'Show' ?></label></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <button class="btn" type="submit" style="margin-top:12px">Save</button>
</form>
<?php admin_page_end();
