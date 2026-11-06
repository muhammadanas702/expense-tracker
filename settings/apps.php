<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
require_once __DIR__ . '/../modules/settings/lib.php';
[$user, $user_id] = settings_boot();
settings_page_start('apps', $user);
?>
    <div class="g card">
      <div class="t">Use ExpenseFlow as an app</div>
      <div class="row" id="install-row" hidden><span><?= ef_icon('phone') ?> Install app<small>Works offline on your phone or PC</small></span><b><a class="lnk" href="#" id="install-btn">Install</a></b></div>
      <div class="row" id="app-dl"><span><?= ef_icon('dl') ?> Android app<small>Download the ExpenseFlow APK</small></span><b><a class="lnk" href="<?= e(settings_public('link_android')) ?>" rel="noopener">Download</a></b></div>
      <?php if (settings_lite_link() !== ''): ?><div class="row" id="app-lite"><span><?= ef_icon('dl') ?> Android Lite app<small>If Google Play Protect blocks the APK. No SMS reading: use Share or paste.</small></span><b><a class="lnk" href="<?= e(settings_lite_link()) ?>" rel="noopener">Download</a></b></div><?php endif; ?>
      <div class="row" id="app-win"><span><?= ef_icon('lap') ?> Windows app<small>Download the ExpenseFlow installer (.exe)</small></span><b><a class="lnk" href="<?= e(settings_public('link_store') ?: settings_public('link_windows')) ?>" rel="noopener">Download</a></b></div>
      <details class="qhelp" id="install-help" style="margin:12px 0 4px">
        <summary>Install help (Android and Windows)</summary>
        <ol>
          <li><?= t('<b>Android:</b> open the downloaded APK and allow installing from this source when Android asks. Updates then come inside the app.') ?></li>
          <li><?= t('<b>Google Play Protect says "App blocked"?</b> It does this for apps that can read SMS when they are installed from outside the Play Store. Install the Lite app instead (no SMS permission), or install the full APK from a computer with <code>adb install -r ExpenseFlow.apk</code>.') ?></li>
          <li><?= t('<b>Windows:</b> if it shows "protected your PC", choose More info, then Run anyway.') ?></li>
        </ol>
      </details>
      <div class="row" id="app-upd" hidden><span><?= ef_icon('ref') ?> App updates<small id="app-ver">Check for a newer version</small></span><b><a class="lnk" href="#" id="app-upd-btn">Check</a></b></div>
    </div>
<?php
$js = '<script>(function(){var a=window.EFApp;if(!a)return;var d=document.getElementById("app-dl"),u=document.getElementById("app-upd");if(d)d.hidden=true;var w=document.getElementById("app-win");if(w)w.hidden=true;if(u){u.hidden=false;document.getElementById("app-ver").textContent="Installed version "+a.version();document.getElementById("app-upd-btn").addEventListener("click",function(e){e.preventDefault();a.checkUpdate()})}})();</script><script>(function(){var p=null,r=document.getElementById("install-row"),b=document.getElementById("install-btn");window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();p=e;r.hidden=false});b.addEventListener("click",function(e){e.preventDefault();if(p){p.prompt();p=null;r.hidden=true}});window.addEventListener("appinstalled",function(){r.hidden=true})})()</script>';
settings_page_end($js);
