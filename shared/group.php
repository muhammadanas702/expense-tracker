<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$gid = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$group = $gid ? shared_group_for($conn, $gid, $user_id) : null;
if (!$group) {
    $_SESSION['error'] = 'Group not found.';
    shared_back('shared/groups.php');
}
$isOwner = $group['my_role'] === 'owner';
$members = shared_members_of($conn, $gid);
$csrf = csrf_token();
$invite = shared_invite_url($group['invite_code']);
$wa = 'https://wa.me/?text=' . rawurlencode('Join my ExpenseFlow group "' . $group['name'] . '": ' . $invite);
$action = ef_url('shared/action.php');

ef_page_start(['title' => $group['name'], 'sub' => count($members) . ' member' . (count($members) === 1 ? '' : 's'), 'mode' => 's', 'active' => 'grp', 'user' => $user]);
?>
<div class="g card hero">
  <div class="gh">
    <div class="gi" style="margin:0"><?= ef_icon(shared_icon_key($group['icon']), '#1d4ed8') ?></div>
    <div style="flex:1"><b style="font-size:1.1rem"><?= e($group['name']) ?></b>
      <div class="stk" style="margin-top:6px"><?php foreach (array_slice($members, 0, 6) as $i => $m) { echo shared_avatar($m['name'], $i); } ?></div></div>
    <div style="text-align:right"><div class="k">Total spent</div><div class="v" style="margin:0">0.00</div></div>
  </div>
</div>

<div class="G2">
  <div class="g card">
    <div class="t">Members <span class="k"><?= count($members) ?></span></div>
    <?php foreach ($members as $i => $m): ?>
    <div class="row">
      <?= shared_avatar($m['name'], $i) ?>
      <span><?= e($m['name']) ?><?= (int) $m['id'] === $user_id ? ' (you)' : '' ?><small><?= $m['role'] === 'owner' ? 'Owner' : 'Member' ?> · joined <?= e(date('d M Y', strtotime($m['joined_at']))) ?></small></span>
      <?php if ($isOwner && (int) $m['id'] !== $user_id): ?>
      <span class="acts"><a class="del" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"remove","group_id":<?= $gid ?>,"user_id":<?= (int) $m['id'] ?>}' onclick="return confirm('Remove <?= e(addslashes($m['name'])) ?> from this group?')" aria-label="Remove member"><?= ef_icon('tr') ?></a></span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="g card">
      <div class="t">Invite friends</div>
      <div class="fl"><label for="invite">Invite link</label><input class="inp" id="invite" type="text" value="<?= e($invite) ?>" readonly onclick="this.select()"></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="button" id="copy-invite"><?= ef_icon('link') ?> Copy link</button>
        <a class="btn i2" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= ef_icon('msg') ?> WhatsApp</a>
        <?php if ($isOwner): ?>
        <a class="btn o" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"regen","group_id":<?= $gid ?>}' onclick="return confirm('Create a new link? The old link will stop working.')">New link</a>
        <?php endif; ?>
      </div>
      <p class="k" style="margin-top:10px">Anyone with this link can join after signing in. Create a new link to stop old invites.</p>
    </div>

    <?php if ($isOwner): ?>
    <div class="g card">
      <div class="t">Group settings</div>
      <form method="POST" action="<?= e($action) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="rename"><input type="hidden" name="group_id" value="<?= $gid ?>">
        <div class="fl"><label for="gname">Name</label><input class="inp" id="gname" type="text" name="name" maxlength="60" value="<?= e($group['name']) ?>" required></div>
        <div class="fl"><label>Icon</label>
          <div class="chips">
            <?php foreach (shared_icons() as $key => $label): ?>
            <label><input type="radio" name="icon" value="<?= e($key) ?>"<?= $group['icon'] === $key ? ' checked' : '' ?>><span class="chip"><?= ef_icon($key) ?><?= e($label) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <button class="btn" type="submit">Save</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="g card dz">
      <div class="t"><?= ef_icon('warn', '#ea580c') ?> <?= $isOwner ? 'Delete group' : 'Leave group' ?></div>
      <?php if ($isOwner): ?>
        <p class="k">Deletes the group for everyone. This cannot be undone.</p>
        <a class="btn o sm" style="color:#b91c1c;margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"delete","group_id":<?= $gid ?>}' onclick="return confirm('Delete this group for everyone?')">Delete group</a>
      <?php else: ?>
        <p class="k">You will no longer see this group.</p>
        <a class="btn o sm" style="color:#b91c1c;margin-top:10px" href="<?= e($action) ?>" data-post data-csrf="<?= e($csrf) ?>" data-fields='{"do":"leave","group_id":<?= $gid ?>}' onclick="return confirm('Leave this group?')">Leave group</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$js = '<script>(function(){var b=document.getElementById("copy-invite"),i=document.getElementById("invite");if(!b)return;b.addEventListener("click",function(){i.select();var done=function(){b.lastChild.textContent=" Copied!";setTimeout(function(){b.lastChild.textContent=" Copy link"},1800)};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done()})}else{document.execCommand("copy");done()}})})()</script>';
ef_page_end(['mode' => 's', 'active' => 'grp', 'scripts' => $js]);
