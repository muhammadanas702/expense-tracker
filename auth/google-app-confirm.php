<?php
// Browser step of "Continue with Google" for the apps: confirm the 4-letter code the app is showing.
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../modules/oauth/lib.php';

header('Cache-Control: no-store');
$pending = $_SESSION['g_app_confirm'] ?? null;
$hand = is_array($pending) && time() - (int) $pending['t'] < HANDOFF_TTL ? handoff_waiting($conn, (string) $pending['id']) : null;
$state = 'ask';
if (!$hand) {
    $state = 'expired';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (($_POST['do'] ?? '') === 'yes' && handoff_approve($conn, (string) $pending['id'], (int) $pending['user'])) {
        logAction($conn, (int) $pending['user'], 'login', 'User logged in with Google (app)');
        $state = 'done';
    } else {
        handoff_cancel($conn, (string) $pending['id']);
        $state = 'cancelled';
    }
    unset($_SESSION['g_app_confirm']);
}

require_once __DIR__ . '/../includes/auth-layout.php';
ef_auth_start(['title' => 'Sign in to the app']); ?>
<?php if ($state === 'ask'): ?>
<div class="big"><?= ef_icon('shield') ?></div>
<h2 class="c2">Sign in to the ExpenseFlow app?</h2>
<p class="s3 c2">Signed in with Google as <b><?= e($pending['name']) ?></b>. Is this the code your app is showing?</p>
<div class="hocode" aria-label="App code"><?= e($hand['code']) ?></div>
<form method="POST">
    <?= csrf_field() ?>
    <button class="btn lg" style="width:100%" type="submit" name="do" value="yes">Yes, sign in</button>
    <div style="height:10px"></div>
    <button class="btn o lg" style="width:100%" type="submit" name="do" value="no">No, this is not my code</button>
</form>
<p class="gnote">Only continue if you started this on your own phone or computer.</p>
<?php elseif ($state === 'done'): ?>
<div class="big"><?= ef_icon('check') ?></div>
<h2 class="c2">You are signed in</h2>
<p class="s3 c2">Go back to the ExpenseFlow app. It will open your account in a few seconds. You can close this tab.</p>
<?php elseif ($state === 'cancelled'): ?>
<h2 class="c2">Sign-in cancelled</h2>
<p class="s3 c2">Nothing was signed in. You can close this tab.</p>
<?php else: ?>
<h2 class="c2">This link has expired</h2>
<p class="s3 c2">Go back to the app and press "Continue with Google" again.</p>
<?php endif; ?>
<?php ef_auth_end();
