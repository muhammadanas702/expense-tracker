<?php
require_once __DIR__ . '/../includes/security.php';
secure_session_start();

require_once __DIR__ . '/../includes/auth-layout.php';
?>
<?php
$fpMsg = (string) ($_SESSION['reset_message'] ?? '');
$fpType = (($_SESSION['reset_type'] ?? '') === 'success') ? 'ok' : 'err';
unset($_SESSION['reset_message'], $_SESSION['reset_type']);
ef_auth_start(['title' => 'Forgot password']); ?>
<div class="big"><?= ef_icon('key') ?></div>
<h2 class="c2">Forgot password?</h2>
<p class="s3 c2">Enter your email and we will send you a 6-digit code to reset it.</p>
<?= ef_msg($fpMsg, $fpType) ?>
<form action="send-otp.php" method="POST">
    <?= csrf_field() ?>
    <?= ef_field('EMAIL', 'email', 'mail', 'email', '', ['placeholder' => 'you@example.com', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email']) ?>
    <button class="btn lg" style="width:100%" type="submit">Send code</button>
</form>
<div class="aft"><a class="lnk" href="login.php"><?= ef_icon('bk') ?> Back to login</a></div>
<?php ef_auth_end(); ?>
