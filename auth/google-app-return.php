<?php
// Last browser step of "Continue with Google" for the apps: send the person back to the app (deep link / local port).
require_once __DIR__ . '/../includes/security.php';
secure_session_start();
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/auth-layout.php';

header('Cache-Control: no-store');
$ret = $_SESSION['g_app_return'] ?? null;
unset($_SESSION['g_app_return']); // one look: the proof is not kept in the browser
$url = is_array($ret) && time() - (int) $ret['t'] < 120 ? (string) $ret['url'] : '';
// only our own app address or the local app port: never an arbitrary address
$okUrl = (bool) preg_match('#^(expenseflow://auth\?|http://127\.0\.0\.1:[0-9]{4,5}/done\?)h=[0-9a-f]{32}&p=[0-9a-f]{48}$#', $url);

ef_auth_start(['title' => 'Back to the app']); ?>
<?php if ($okUrl): ?>
<div class="big"><?= ef_icon('check') ?></div>
<h2 class="c2">You are signed in with Google</h2>
<p class="s3 c2">Taking you back to the ExpenseFlow app…</p>
<a class="btn lg" style="width:100%" id="back" href="<?= e($url) ?>">Open ExpenseFlow app</a>
<p class="gnote">If nothing happens, tap the button. You can close this tab afterwards.</p>
<script>setTimeout(function () { location.href = document.getElementById('back').href; }, 150);</script>
<?php else: ?>
<h2 class="c2">This link has expired</h2>
<p class="s3 c2">Go back to the ExpenseFlow app and press "Continue with Google" again.</p>
<?php endif; ?>
<?php ef_auth_end();
