<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../modules/profile/settings-ui.php';
[$user, $user_id] = settings_boot();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        settings_done('', 'Your session expired. Please try again.');
    }
    $new_name = clean_text($_POST['name'] ?? '', 100);
    if ($new_name === null) {
        settings_done('', 'Please enter a name (up to 100 characters).');
    }
    $conn->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$new_name, $user_id]);
    $_SESSION['user_name'] = $new_name;
    logAction($conn, $user_id, 'update_profile', 'Updated name');
    settings_done('Profile updated successfully.');
}
settings_page_start('account', $user);
?>
    <div class="g card">
      <div style="display:flex;gap:14px;align-items:center;margin-bottom:14px">
        <i class="av" style="width:56px;height:56px;font-size:1.1rem"><?= e(ef_initials($user['name'])) ?></i>
        <div><b style="font-size:1.05rem"><?= e($user['name']) ?></b><div class="k"><?= e($user['email']) ?></div></div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="fl"><label for="name">Name</label><input class="inp" id="name" type="text" name="name" maxlength="100" value="<?= e($user['name']) ?>" required></div>
        <div class="fl"><label for="email">E-mail</label><input class="inp" id="email" type="email" value="<?= e($user['email']) ?>" readonly><small class="k">Your e-mail is your sign-in. To change it, contact support.</small></div>
        <button class="btn" type="submit">Update profile</button>
      </form>
    </div>
<?php settings_page_end();
