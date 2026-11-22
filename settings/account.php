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
    require_once __DIR__ . '/../modules/friends/username.php';
    if (array_key_exists('username', $_POST)) {
        $ur = friends_username_set($conn, $user_id, (string) $_POST['username']);
        if (!$ur['ok']) {
            settings_done('', $ur['msg']);
        }
    }
    $conn->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$new_name, $user_id]);
    $_SESSION['user_name'] = $new_name;
    logAction($conn, $user_id, 'update_profile', 'Updated name');
    settings_done('Profile updated successfully.');
}
require_once __DIR__ . '/../modules/friends/username.php';
$myUsername = friends_username_of($conn, $user_id);
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
        <div class="fl"><label for="username">Username <span class="k">(optional)</span></label><input class="inp" id="username" type="text" name="username" maxlength="20" value="<?= e((string) $myUsername) ?>" placeholder="e.g. ali.raza" autocomplete="off" autocapitalize="none" spellcheck="false"><small class="k">Friends can find you with @username instead of your e-mail. 3 to 20 letters, digits, "_" or ".". Leave empty for none.</small></div>
        <div class="fl"><label for="email">E-mail</label><input class="inp" id="email" type="email" value="<?= e($user['email']) ?>" readonly><small class="k">Your e-mail is your sign-in. To change it, contact support.</small></div>
        <button class="btn" type="submit">Update profile</button>
      </form>
    </div>
<?php settings_page_end();
