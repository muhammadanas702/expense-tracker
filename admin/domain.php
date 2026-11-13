<?php
/** Admin > Domain: connect your own domain (for example expenseflow.page) and make it the main address. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/site/host.php';

$c = site_config();
$live = site_live_base() !== null;
$last = $_SESSION['domain_check'] ?? null;
unset($_SESSION['domain_check']);
$hostVal = $last['host'] ?? ($c['host'] !== '' ? $c['host'] : 'expenseflow.page');
admin_page_start(['title' => 'Domain', 'sub' => $live ? 'Main address: ' . $c['host'] : 'Main address: ' . SITE_LEGACY_HOST, 'active' => 'dom']);
?>
<div class="g card">
  <div class="t">Main address</div>
  <p style="margin:0 0 4px"><b><?= e($live ? $c['host'] : SITE_LEGACY_HOST) ?></b> <?= $live ? '<span class="tag">your own domain</span>' : '<span class="tag">free address</span>' ?></p>
  <p class="mut" style="margin:0"><?= $live ? 'Every other address is sent here since ' . e(date('d M Y, H:i', $c['since'])) . '. The free address still opens the admin pages, so you can always come back.' : 'The site is at the free address. Connect your domain with the steps below, run the check, then make it the main address.' ?></p>
  <?php if ($live): ?>
  <form method="post" action="domain-action.php" style="margin-top:12px" onsubmit="return confirm('Go back to the free address?')"><?= csrf_field() ?><button class="btn o sm" type="submit" name="do" value="revert">Go back to <?= e(SITE_LEGACY_HOST) ?></button></form>
  <?php endif; ?>
</div>

<div class="g card">
  <div class="t">Check your domain</div>
  <form method="post" action="domain-action.php" class="row" style="gap:10px;flex-wrap:wrap;align-items:center;border:0;padding:0">
    <?= csrf_field() ?>
    <input class="inp" type="text" name="host" value="<?= e($hostVal) ?>" maxlength="100" autocomplete="off" aria-label="Domain" style="flex:1;min-width:200px">
    <button class="btn sm" type="submit" name="do" value="check">Check</button>
    <button class="btn i2 sm" type="submit" name="do" value="switch" onclick="return confirm('Make this the main address? Everyone is sent to it and has to sign in again.')">Make it the main address</button>
  </form>
  <?php if ($last): ?>
  <div style="margin-top:12px" id="domcheck">
    <?php $names = ['dns' => '1. The domain points to a server', 'https' => '2. The secure address (https) answers', 'app' => '3. ExpenseFlow answers']; foreach ($last['steps'] as [$k, $ok, $txt]): ?>
      <div class="row" style="border:0;padding:6px 0"><span><b><?= e($names[$k] ?? $k) ?></b><small><?= e($txt) ?></small></span><b style="color:<?= $ok ? '#0d9f6e' : '#b45309' ?>"><?= $ok ? 'OK' : 'Not yet' ?></b></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <p class="mut" style="margin:12px 0 0;font-size:.85rem">"Make it the main address" runs the same check first and does nothing if it fails.</p>
</div>

<div class="g card">
  <div class="t">Steps to connect expenseflow.page</div>
  <ol style="margin:0;padding-left:20px;line-height:1.7">
    <li>InfinityFree client area &rarr; your account &rarr; <b>Addon Domains</b> (or <b>Domain Names</b>) &rarr; add <b>expenseflow.page</b> and <b>www.expenseflow.page</b>. Note the server IP address shown for the account.</li>
    <li>Cloudflare (free) &rarr; <b>Add a site</b> &rarr; expenseflow.page &rarr; Free plan. Cloudflare shows two name servers.</li>
    <li>name.com &rarr; My Domains &rarr; expenseflow.page &rarr; <b>Nameservers</b> &rarr; replace them with the two from Cloudflare. Wait until Cloudflare says Active.</li>
    <li>Cloudflare &rarr; DNS: an <b>A</b> record <code>@</code> and an <b>A</b> record <code>www</code> with the InfinityFree IP. Set both to <b>DNS only</b> (grey cloud) first.</li>
    <li>InfinityFree &rarr; <b>Free SSL Certificates</b> &rarr; request one for expenseflow.page, then install it when it is ready (usually minutes, sometimes a few hours).</li>
    <li>Come back here, press <b>Check</b>. When all three show OK, press <b>Make it the main address</b>.</li>
    <li>Optional: turn the Cloudflare cloud orange and set SSL/TLS to <b>Full</b>. If the site shows a redirect-loop error, set it back to DNS only.</li>
  </ol>
  <p class="mut" style="margin:10px 0 0;font-size:.85rem">The full guide with every screen and the fixes for common problems is <code>docs/DOMAIN.md</code>.</p>
</div>
<?php admin_page_end();
