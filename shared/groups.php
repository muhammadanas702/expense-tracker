<?php
require_once __DIR__ . '/../modules/shared/boot.php';
$groups = shared_groups_of($conn, $user_id);
$csrf = csrf_token();

ef_page_start(['title' => 'Groups', 'sub' => 'Trips, rent, family and friends', 'mode' => 's', 'active' => 'grp', 'user' => $user]);
?>
<div class="G2">
  <div class="g card">
    <div class="t">Your groups <span class="k"><?= count($groups) ?></span></div>
    <?php if ($groups): ?>
    <div class="G4" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
      <?php foreach ($groups as $gr): ?>
      <a class="g gc" href="<?= e(ef_url('shared/group.php?id=' . (int) $gr['id'])) ?>">
        <div class="gi"><?= ef_icon(shared_icon_key($gr['icon']), '#1d4ed8') ?></div>
        <b><?= e($gr['name']) ?></b>
        <div class="k" style="margin-top:4px"><?= (int) $gr['member_count'] ?> member<?= (int) $gr['member_count'] === 1 ? '' : 's' ?><?= $gr['my_role'] === 'owner' ? ' · owner' : '' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="k" style="padding:12px 0">You are not in any group yet. Create one, or open an invite link a friend sent you.</p>
    <?php endif; ?>
  </div>

  <div class="g card">
    <div class="t">Create a group</div>
    <form method="POST" action="<?= e(ef_url('shared/action.php')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="create">
      <div class="fl"><label for="gname">Group name</label><input class="inp" id="gname" type="text" name="name" maxlength="60" placeholder="e.g. Murree Trip" required></div>
      <div class="fl"><label>Icon</label>
        <div class="chips">
          <?php foreach (shared_icons() as $key => $label): ?>
          <label><input type="radio" name="icon" value="<?= e($key) ?>"<?= $key === 'users' ? ' checked' : '' ?>><span class="chip"><?= ef_icon($key) ?><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <button class="btn" type="submit" style="width:100%;padding:14px">Create group</button>
    </form>
  </div>
</div>
<?php ef_page_end(['mode' => 's', 'active' => 'grp']);
