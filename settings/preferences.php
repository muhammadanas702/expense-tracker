<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        settings_done('', 'Your session expired. Please try again.');
    }
    $goal = filter_var($_POST['savings_goal'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
    if ($goal === false || $goal === null) {
        settings_done('', 'Savings goal must be a whole number from 0 to 100.');
    }
    $cur = profile_settings($conn, $user_id);
    profile_save_settings($conn, $user_id, ['savings_goal' => $goal, 'notifications' => (bool) $cur['notifications']]);
    logAction($conn, $user_id, 'update_profile', 'Updated preferences');
    settings_done('Preferences saved.');
}
$settings = profile_settings($conn, $user_id);
settings_page_start('preferences', $user);
?>
    <div class="g card">
      <div class="t">Appearance</div>
      <p class="k" style="margin:-4px 0 12px">Light, dark, or follow your device. Saved on this device.</p>
      <div class="thm" id="thm" role="group" aria-label="Theme">
        <button type="button" data-t="auto">Auto</button><button type="button" data-t="light">Light</button><button type="button" data-t="dark">Dark</button>
      </div>
    </div>
    <div class="g card">
      <form method="POST">
        <?= csrf_field() ?>
        <div class="fl"><label for="savings_goal">Monthly savings goal (% of income)</label>
          <input class="inp" id="savings_goal" type="number" name="savings_goal" min="0" max="100" step="1" value="<?= (int) $settings['savings_goal'] ?>" required></div>
        <div class="row"><span><?= ef_icon('globe') ?> Base currency<small>Chosen per month on the dashboard</small></span><b><a class="lnk" href="<?= e($base_url) ?>/dashboard.php#base">Change</a></b></div>
        <button class="btn" type="submit" style="margin-top:10px">Save preferences</button>
      </form>
    </div>
<?php settings_page_end('<script>(function(){var box=document.getElementById("thm");if(!box)return;var m=document.cookie.match(/(?:^|; )ef_theme=(auto|light|dark)/),cur=m?m[1]:"auto";'
  . 'function apply(t){var dark=t==="dark"||(t==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches);if(dark)document.documentElement.setAttribute("data-theme","dark");else document.documentElement.removeAttribute("data-theme");'
  . '[].forEach.call(box.querySelectorAll("button"),function(b){b.setAttribute("aria-pressed",b.getAttribute("data-t")===t?"true":"false");});}'
  . 'box.addEventListener("click",function(e){var b=e.target.closest("button");if(!b)return;var t=b.getAttribute("data-t");document.cookie="ef_theme="+t+";path=/;max-age=31536000;SameSite=Lax";apply(t);});apply(cur);})();</script>');
