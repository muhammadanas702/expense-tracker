<?php
/** "Where do I go?": plain-words problems, each pointing at the admin page that fixes it. For team members who are not technical. */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../modules/admin/help-topics.php';

/** problem => [what it usually means, [[menu key, why open it], ...]] grouped by theme. */
$topics = admin_help_topics();

$byKey = [];
foreach (admin_visible_menu($conn, $admin['role']) as $items) {
    foreach ($items as $i) {
        $byKey[$i[0]] = $i;
    }
}
admin_page_start(['title' => 'Where do I go?', 'sub' => 'Find the right page for your problem', 'active' => 'help']);
?>
<style>
.hp-find{display:flex;gap:10px;align-items:center;margin-bottom:14px}
.hp-find input{flex:1;min-width:0}
.hp-t{margin:0 0 6px}
.hp-q{padding:14px 0;border-top:1px solid var(--line,#e2e8f0)}
.hp-q:first-of-type{border-top:0}
.hp-q b{display:block;font-size:1.02rem;line-height:1.35}
.hp-q p{margin:3px 0 8px;line-height:1.5}
.hp-go{display:flex;flex-wrap:wrap;gap:8px}
.hp-go a,.hp-go span{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:8px 14px;border-radius:12px;border:1px solid var(--line,#e2e8f0);text-decoration:none;font-weight:600;line-height:1.3}
.hp-go a:hover{border-color:var(--a1)}
.hp-go svg{width:18px;height:18px;flex:none}
.hp-go small{display:block;font-weight:400;opacity:.8}
.hp-go span{opacity:.6;border-style:dashed}
.hp-none{display:none;text-align:center;padding:18px}
</style>
<div class="g card" style="margin-bottom:14px">
  <b>Something is wrong? Find it here.</b>
  <p class="mut" style="margin:4px 0 10px">Look for the sentence that sounds like your problem, then press the button under it. You do not need any technical knowledge.</p>
  <div class="hp-find"><input class="inp" type="search" id="hp-q" placeholder="Type a word, like: email, slow, login, error, logo" aria-label="Search problems" autocomplete="off"></div>
</div>
<?php foreach ($topics as $theme => $qs): ?>
<div class="g card hp-grp" style="margin-bottom:14px">
  <h3 class="hp-t"><?= e($theme) ?></h3>
<?php foreach ($qs as [$q, $why, $go]): ?>
  <div class="hp-q" data-t="<?= e(strtolower($q . ' ' . $why . ' ' . implode(' ', array_map(fn($g) => $g[1], $go)))) ?>">
    <b><?= e($q) ?></b>
    <p class="mut"><?= e($why) ?></p>
    <div class="hp-go">
<?php foreach ($go as [$key, $text]): ?>
<?php if (isset($byKey[$key])): $i = $byKey[$key]; ?>
      <a href="<?= e(ef_url($i[1])) ?>"><?= ef_icon($i[2]) ?><span style="display:block;border:0;padding:0;min-height:0;opacity:1"><?= e($i[3]) ?><small><?= e($text) ?></small></span></a>
<?php else: ?>
      <span title="Your role cannot open this page. Ask the owner."><?= e($text) ?> (ask the owner)</span>
<?php endif; ?>
<?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
<div class="g card hp-none" id="hp-none">Nothing matches that word. Try a shorter one, like "email" or "slow", or look through the list above.</div>
<script>
(function(){var q=document.getElementById('hp-q'),n=document.getElementById('hp-none');
q.addEventListener('input',function(){var v=q.value.trim().toLowerCase(),shown=0;
document.querySelectorAll('.hp-grp').forEach(function(g){var any=0;g.querySelectorAll('.hp-q').forEach(function(r){var m=!v||r.getAttribute('data-t').indexOf(v)>-1;r.style.display=m?'':'none';if(m){any++;shown++}});g.style.display=any?'':'none'});
n.style.display=shown?'none':'block'})})();
</script>
<?php admin_page_end();
