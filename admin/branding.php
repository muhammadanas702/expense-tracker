<?php
/** Branding: site name, tagline, two colours, logo, pictures and texts. Staff with the settings permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_once __DIR__ . '/../modules/branding/lib.php';

$d = brand_data(true);
$old = $_SESSION['brand_old'] ?? null;
unset($_SESSION['brand_old']);
$v = is_array($old) ? array_merge($d, $old) : $d;
$a1 = $v['a1'] !== '' ? $v['a1'] : BRAND_DEFAULT_A1;
$a2 = $v['a2'] !== '' ? $v['a2'] : BRAND_DEFAULT_A2;
$logo = brand_has_logo() ? '../' . brand_logo_path() : '../' . BRAND_BUILTIN_LOGO;
$name = $v['name'];
$texts = brand_texts(true);
$editing = brand_edit_on();
admin_page_start(['title' => 'Branding', 'sub' => brand_active() ? 'Custom look is ON' : 'Built-in look', 'active' => 'brand']);
?>
<style>
.br-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:14px;align-items:start}
@media(max-width:860px){.br-grid{grid-template-columns:1fr}}
.br-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.br-col{display:flex;gap:8px;align-items:center}
.br-col input[type=color]{width:46px;height:42px;padding:2px;border-radius:10px;border:1px solid var(--line,#d6dde6);background:#fff;cursor:pointer;flex:none}
.br-col .inp{min-width:0}
.br-prev{padding:20px;border-radius:18px;background:linear-gradient(135deg,#f3f8f7,#e8f1fb)}
.br-prev .lg{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.br-prev .lg img{width:40px;height:40px;border-radius:10px;object-fit:contain;background:#fff}
.br-prev .lg b{font-size:1.25rem;font-weight:800;background:linear-gradient(135deg,var(--p1),var(--p2));-webkit-background-clip:text;background-clip:text;color:transparent}
.br-prev .btnp{display:inline-block;padding:10px 18px;border-radius:12px;color:#fff;font-weight:700;background:linear-gradient(135deg,var(--p1),var(--p2))}
.br-pics{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px}
.br-pic{display:flex;gap:14px;align-items:flex-start;padding:12px;border:1px solid var(--line,#e2e8f0);border-radius:14px;min-width:0}
.br-pic img{flex:none;border-radius:10px;background:#eef2f7;object-fit:cover;border:1px solid var(--line,#e2e8f0)}
.br-pic img.tall{width:84px;height:168px}.br-pic img.wide{width:150px;height:79px}
.br-pic .bd{min-width:0;flex:1}
@media(max-width:420px){.br-pic{flex-direction:column}.br-pic .bd{width:100%}}
.br-prev p{margin:0 0 14px;color:#475569;font-size:.9rem;line-height:1.5}
</style>
<div class="br-grid">
<form method="post" action="branding-action.php" enctype="multipart/form-data" class="g card">
  <?= csrf_field() ?>
  <div class="fl"><label for="b_name">Site name</label>
    <input class="inp" id="b_name" name="name" maxlength="<?= BRAND_NAME_MAX ?>" value="<?= e($name) ?>" placeholder="<?= e(BRAND_BUILTIN) ?>">
    <small class="k">Shown in the header, footer, sign-in pages and e-mails. Empty = <?= e(BRAND_BUILTIN) ?>.</small></div>
  <div class="fl" style="margin-top:12px"><label for="b_tag">Tagline</label>
    <textarea class="inp" id="b_tag" name="tagline" rows="2" maxlength="<?= BRAND_TAGLINE_MAX ?>"><?= e($v['tagline']) ?></textarea>
    <small class="k">One sentence under the logo in the footer.</small></div>
  <div class="br-two" style="margin-top:12px">
    <div class="fl"><label for="b_a1">Main colour</label><div class="br-col"><input type="color" id="b_a1c" value="<?= e($a1) ?>" aria-label="Pick main colour"><input class="inp" id="b_a1" name="a1" maxlength="7" value="<?= e($a1) ?>"></div></div>
    <div class="fl"><label for="b_a2">Second colour</label><div class="br-col"><input type="color" id="b_a2c" value="<?= e($a2) ?>" aria-label="Pick second colour"><input class="inp" id="b_a2" name="a2" maxlength="7" value="<?= e($a2) ?>"></div></div>
  </div>
  <small class="k" style="display:block;margin-top:4px">Buttons and highlights use these two colours. White text sits on them, so very light colours are refused.</small>
  <div class="fl" style="margin-top:12px"><label for="b_logo">Logo</label>
    <input class="inp" id="b_logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
    <small class="k">PNG, JPG or WebP, at least 64 x 64 px, up to 1.5 MB. It is fitted into a square and also becomes the browser tab icon, the phone home-screen icon and the logo inside the app. A square logo of 256 px or more looks sharpest. Apps already installed from the Play Store / Windows installer (APK/exe) keep their own icon.</small>
    <?php if (brand_has_logo()): ?><label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="remove_logo" value="1"> Remove my logo (use the built-in one)</label><?php endif; ?></div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
    <button class="btn" type="submit" name="do" value="save">Save</button>
    <button class="btn o" type="submit" name="do" value="reset" onclick="return confirm('Go back to the built-in name, colours, logo and pictures? (Edited texts are kept; restore them below.)')">Reset to built-in</button>
  </div>
</form>
<div class="g card">
  <div class="t">Preview</div>
  <div class="br-prev" id="br-prev" style="--p1:<?= e($a1) ?>;--p2:<?= e($a2) ?>">
    <div class="lg"><img id="br-img" src="<?= e($logo) ?>" alt=""><b id="br-name"><?= e($name) ?></b></div>
    <p id="br-tag"><?= e($v['tagline']) ?></p>
    <span class="btnp">Get started</span>
  </div>
  <small class="k" style="display:block;margin-top:10px">A quick look only. Save to apply it to every page.</small>
</div>
</div>
<div class="g card" style="margin-top:14px" id="pictures">
  <div class="t">Pictures on the website</div>
  <p class="mut" style="margin:0 0 12px">Replace the phone screenshots on the home and sign-in pages and the picture people see when your link is shared. Pictures are cut to the right shape automatically (PNG, JPG or WebP, up to 4 MB).</p>
  <div class="br-pics">
<?php foreach (BRAND_IMAGE_SLOTS as $slot => $c): $own = brand_image_file($slot) !== null; $src = $own ? '../brand-image.php?' . $slot . '=' . (int) @filemtime(brand_image_file($slot)) : '../' . $c['builtin']; ?>
    <div class="br-pic" data-slot="<?= e($slot) ?>">
      <img src="<?= e($src) ?>" alt="" class="<?= $slot === 'share' ? 'wide' : 'tall' ?>" loading="lazy">
      <div class="bd">
        <b><?= e($c['label']) ?></b> <span class="tag <?= $own ? 'tg' : '' ?>"><?= $own ? 'Your picture' : 'Built-in' ?></span>
        <small class="k" style="display:block;margin:4px 0 8px"><?= e($c['where']) ?></small>
        <form method="post" action="branding-action.php" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0">
          <?= csrf_field() ?><input type="hidden" name="slot" value="<?= e($slot) ?>">
          <input class="inp" type="file" name="img" accept="image/png,image/jpeg,image/webp" aria-label="Choose a picture for <?= e($c['label']) ?>" style="flex:1 1 100%;min-width:0">
          <button class="btn sm" type="submit" name="do" value="image">Upload</button>
          <?php if ($own): ?><button class="btn sm ghost" type="submit" name="do" value="image_remove">Use built-in</button><?php endif; ?>
        </form>
      </div>
    </div>
<?php endforeach; ?>
  </div>
</div>
<div class="g card" style="margin-top:14px" id="texts">
  <div class="t">Edit any text on the website and the app</div>
  <p class="mut" style="margin:0 0 12px">Headlines, buttons, labels, hints, footer, sign-in pages, guides, dashboard and every other page. Press the button, then open any page as you normally would: <b>click a text, type the new words, Save</b>. It changes everywhere that exact text appears. In Urdu mode you edit the Urdu wording. Texts with changing numbers or names (like "Welcome, Ali") can only be changed as a whole sentence, and e-mails are not covered.</p>
  <form method="post" action="brand-text-action.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?= csrf_field() ?>
    <button class="btn" type="submit" name="do" value="edit_on"><?= $editing ? 'Open the website (edit mode is on)' : 'Start editing texts' ?></button>
    <?php if ($editing): ?><button class="btn o" type="submit" name="do" value="edit_off">Stop editing</button><?php endif; ?>
    <?php if ($texts): ?><button class="btn o" type="submit" name="do" value="reset_all" onclick="return confirm('Restore ALL <?= count($texts) ?> edited texts to the original words?')">Restore all texts</button><?php endif; ?>
  </form>
<?php if ($texts): ?>
  <div class="t" style="margin:20px 0 8px"><?= count($texts) ?> edited text<?= count($texts) === 1 ? '' : 's' ?></div>
  <div class="tbl-wrap"><table class="tbl fixm wide">
    <tr><th>Original</th><th>English now</th><th>Urdu now</th><th></th></tr>
<?php foreach (array_slice($texts, 0, 300, true) as $k => $tv): ?>
    <tr><td><?= e(mb_strimwidth($k, 0, 70, '…')) ?></td><td><?= isset($tv['en']) ? e(mb_strimwidth($tv['en'], 0, 70, '…')) : '<span class="k">original</span>' ?></td><td dir="auto"><?= isset($tv['ur']) ? e(mb_strimwidth($tv['ur'], 0, 70, '…')) : '<span class="k">original</span>' ?></td>
      <td class="nw"><form method="post" action="brand-text-action.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($k) ?>"><button class="btn sm ghost" type="submit" name="do" value="clear">Restore</button></form></td></tr>
<?php endforeach; ?>
  </table></div>
<?php else: ?>
  <p class="k" style="margin:16px 0 0">No text has been changed yet.</p>
<?php endif; ?>
</div>
<script>
(function () {
  var $ = function (i) { return document.getElementById(i); }, prev = $('br-prev');
  function hex(v) { return /^#[0-9a-fA-F]{6}$/.test(v); }
  ['a1', 'a2'].forEach(function (k, n) {
    var t = $('b_' + k), c = $('b_' + k + 'c');
    c.addEventListener('input', function () { t.value = c.value; prev.style.setProperty('--p' + (n + 1), c.value); });
    t.addEventListener('input', function () { if (hex(t.value)) { c.value = t.value; prev.style.setProperty('--p' + (n + 1), t.value); } });
  });
  $('b_name').addEventListener('input', function (e) { $('br-name').textContent = e.target.value || <?= json_encode(BRAND_BUILTIN) ?>; });
  $('b_tag').addEventListener('input', function (e) { $('br-tag').textContent = e.target.value; });
  $('b_logo').addEventListener('change', function (e) { var f = e.target.files[0]; if (f) { $('br-img').src = URL.createObjectURL(f); } });
})();
</script>
<?php admin_page_end();
