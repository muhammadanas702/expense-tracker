<?php
/**
 * Google sign-in rules against a TEST database (no network): claim checks and find / link / create / refuse.
 * Usage: php tests/google_signin_test.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/oauth/lib.php';
oauth_ensure_schema($conn);
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }

$cid = 'cid.apps.googleusercontent.com';
$good = ['iss' => 'https://accounts.google.com', 'aud' => $cid, 'exp' => time() + 600, 'sub' => 'g-sub-1', 'email' => 'g1@google-test.local', 'email_verified' => true, 'name' => 'Gee One'];
ok(google_claims_error($good, $cid) === null, 'good claims accepted');
ok(google_claims_error(['iss' => 'accounts.google.com'] + $good, $cid) === null, 'bare issuer accepted');
ok(google_claims_error(['iss' => 'https://evil.example'] + $good, $cid) !== null, 'wrong issuer refused');
ok(google_claims_error(['aud' => 'other'] + $good, $cid) !== null, 'wrong audience refused');
ok(google_claims_error(['exp' => time() - 5] + $good, $cid) !== null, 'expired refused');
ok(google_claims_error(['email_verified' => false] + $good, $cid) !== null, 'unverified e-mail refused');
$nv = $good; unset($nv['email_verified']);
ok(google_claims_error($nv, $cid) !== null, 'missing email_verified refused');
ok(google_claims_error(['email' => 'not-an-email'] + $good, $cid) !== null, 'bad e-mail refused');
ok(google_claims_error(['sub' => ''] + $good, $cid) !== null, 'empty subject refused');

$conn->exec("DELETE FROM users WHERE email LIKE '%@google-test.local' OR email LIKE '%@gmail.com' OR email LIKE '%@googlemail.com' OR email = 'anas@yahoo.com'"); // also what an aborted earlier run left

// 1. new person: account created and linked
[$u, $e] = google_user_for_claims($conn, $good, true);
ok($u && $e === '' && !empty($u['created']), 'new account created');
$id1 = (int) $u['id'];
ok((int) $conn->query("SELECT COUNT(*) FROM user_identities WHERE user_id=$id1")->fetchColumn() === 1, 'identity linked');
ok((string) $conn->query("SELECT name FROM users WHERE id=$id1")->fetchColumn() === 'Gee One', 'name saved');
ok(strlen((string) $conn->query("SELECT password FROM users WHERE id=$id1")->fetchColumn()) > 40, 'random password stored as a hash');

// 2. same Google person again: same account, no duplicate
[$u2] = google_user_for_claims($conn, $good, true);
ok($u2 && (int) $u2['id'] === $id1 && empty($u2['created']), 'second sign-in finds the same account');
ok((int) $conn->query("SELECT COUNT(*) FROM users WHERE email='g1@google-test.local'")->fetchColumn() === 1, 'no duplicate user');

// 3. existing e-mail/password account gets linked, not duplicated
$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute(['Old Pw', 'old@google-test.local', password_hash('Test#12345', PASSWORD_DEFAULT)]);
$oldId = (int) $conn->lastInsertId();
[$u3] = google_user_for_claims($conn, ['sub' => 'g-sub-2', 'email' => 'OLD@google-test.local'] + $good, true);
ok($u3 && (int) $u3['id'] === $oldId && empty($u3['created']), 'existing account linked by verified e-mail (case-insensitive)');
ok(password_verify('Test#12345', (string) $conn->query("SELECT password FROM users WHERE id=$oldId")->fetchColumn()), 'existing password untouched');

// 4. blocked accounts are refused
$conn->exec("UPDATE users SET blocked=1 WHERE id=$oldId");
[$u4, $e4] = google_user_for_claims($conn, ['sub' => 'g-sub-2', 'email' => 'old@google-test.local'] + $good, true);
ok($u4 === null && $e4 === 'blocked', 'blocked account refused');

// 5. sign-ups closed: new people refused, existing still fine
[$u5, $e5] = google_user_for_claims($conn, ['sub' => 'g-sub-3', 'email' => 'new3@google-test.local'] + $good, false);
ok($u5 === null && $e5 === 'closed', 'closed sign-ups refuse new accounts');
[$u6] = google_user_for_claims($conn, $good, false);
ok($u6 && (int) $u6['id'] === $id1, 'existing Google account still signs in when sign-ups are closed');

// 5b. Gmail spellings find the same person (dots, +tag, googlemail.com) instead of creating a duplicate
$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute(['Dotty', 'an.as+shop@gmail.com', password_hash('Test#12345', PASSWORD_DEFAULT)]);
$dotId = (int) $conn->lastInsertId();
[$g1] = google_user_for_claims($conn, ['sub' => 'g-sub-4', 'email' => 'anas@gmail.com'] + $good, true);
ok($g1 && (int) $g1['id'] === $dotId && empty($g1['created']), 'anas@gmail.com finds an.as+shop@gmail.com');
[$g2] = google_user_for_claims($conn, ['sub' => 'g-sub-5', 'email' => 'a.n.a.s@googlemail.com'] + $good, true);
ok($g2 && (int) $g2['id'] === $dotId && empty($g2['created']), 'googlemail.com with dots finds the same account');
[$g3] = google_user_for_claims($conn, ['sub' => 'g-sub-6', 'email' => 'someoneelse@gmail.com'] + $good, true);
ok($g3 && !empty($g3['created']) && (int) $g3['id'] !== $dotId, 'a different Gmail person gets their own account');
[$g4] = google_user_for_claims($conn, ['sub' => 'g-sub-7', 'email' => 'anas@yahoo.com'] + $good, true);
ok($g4 && !empty($g4['created']), 'dots rule is Gmail only');

// 6. deleting the user removes the identity (no foreign key: older databases have a different users.id type)
require_once __DIR__ . '/../modules/shared/lib.php';
shared_on_user_deleted($conn, $id1);
$conn->exec("DELETE FROM users WHERE id=$id1");
ok((int) $conn->query("SELECT COUNT(*) FROM user_identities WHERE user_id=$id1")->fetchColumn() === 0, 'identity removed with the account (via shared_on_user_deleted)');
// a stale row left behind (deleted user) must not make the person create a new account every time
$conn->prepare("INSERT INTO user_identities(user_id,provider,subject,email) VALUES(?, 'google', 'g-stale', 'x@google-test.local')")->execute([999999]);
[$st1] = google_user_for_claims($conn, ['sub' => 'g-stale', 'email' => 'stale@google-test.local'] + $good, true);
ok($st1 && !empty($st1['created']), 'stale identity: account is created once');
[$st2] = google_user_for_claims($conn, ['sub' => 'g-stale', 'email' => 'stale@google-test.local'] + $good, true);
ok($st2 && (int) $st2['id'] === (int) $st1['id'] && empty($st2['created']), 'stale identity: next sign-in finds the same account');
// table creation does not depend on the users.id type
$conn->exec('DROP TABLE IF EXISTS user_identities');
foreach (glob(__DIR__ . '/../storage/schema/*oauth*') ?: [] as $f) { @unlink($f); }
schema_has($conn, 'user_identities', 'pw_state', true); // the table was dropped: forget the cached answer
oauth_ensure_schema($conn);
ok((int) $conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_identities'")->fetchColumn() === 1, 'user_identities exists without a foreign key');
ok((int) $conn->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_identities' AND CONSTRAINT_TYPE='FOREIGN KEY'")->fetchColumn() === 0, 'no foreign key on user_identities');

// 6b. password offer: new Google accounts are asked once, linked accounts and people with a password are not
[$pa] = google_user_for_claims($conn, ['sub' => 'g-pw-new', 'email' => 'pwnew@google-test.local'] + $good, true);
ok($pa && !empty($pa['created']) && oauth_pw_state($conn, (int) $pa['id']) === 1, 'new Google account: password never asked yet (1)');
ok(google_landing_path($conn, (int) $pa['id']) === 'auth/google-password.php', 'first Google sign-in lands on the password offer');
oauth_set_pw_state($conn, (int) $pa['id'], 2);
ok(google_landing_path($conn, (int) $pa['id']) === 'dashboard.php', 'after skipping, the dashboard');
ok(oauth_pw_state($conn, (int) $pa['id']) === 2, 'skipped is remembered (2) so Profile keeps offering it');
oauth_password_set($conn, (int) $pa['id']);
ok(oauth_pw_state($conn, (int) $pa['id']) === 0, 'password set: state 0');
$conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')->execute(['Has Pw', 'haspw@google-test.local', password_hash('Test#12345', PASSWORD_DEFAULT)]);
$haspw = (int) $conn->lastInsertId();
[$pb] = google_user_for_claims($conn, ['sub' => 'g-pw-link', 'email' => 'haspw@google-test.local'] + $good, true);
ok($pb && (int) $pb['id'] === $haspw && empty($pb['created']) && oauth_pw_state($conn, $haspw) === 0, 'existing e-mail account linked: logs into it, no password offer, no duplicate');
// older table without pw_state is upgraded, and Google-created accounts that already exist count as "skipped"
$conn->exec('ALTER TABLE user_identities DROP COLUMN pw_state');
$conn->prepare("INSERT INTO user_logs(user_id,action,details,created_at) VALUES(?, 'signup_google', 'x', NOW())")->execute([(int) $pa['id']]);
foreach (glob(__DIR__ . '/../storage/schema/*oauth*') ?: [] as $f) { @unlink($f); }
schema_has($conn, 'user_identities', 'pw_state', true);
oauth_ensure_schema($conn);
ok(schema_has($conn, 'user_identities', 'pw_state', true), 'pw_state column added to an older table');
ok(oauth_pw_state($conn, (int) $pa['id']) === 2 && oauth_pw_state($conn, $haspw) === 0, 'older Google-made account becomes "skipped", linked account stays 0');

// 7. start URL carries state + PKCE; state is single use and expires
file_put_contents(__DIR__ . '/../config/google.local.php', "<?php return ['client_id'=>'$cid','client_secret'=>'s3cret'];");
$_SESSION = [];
$url = google_start_url('login');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
ok(strpos($url, 'https://accounts.google.com/o/oauth2/v2/auth?') === 0, 'goes to Google');
ok(($q['code_challenge_method'] ?? '') === 'S256' && strlen($q['code_challenge'] ?? '') === 43, 'PKCE S256 challenge');
ok(($q['scope'] ?? '') === 'openid email profile' && ($q['client_id'] ?? '') === $cid, 'scope and client id');
ok(google_take_state('wrong', $w) === null && $w === 'mismatch', 'wrong state refused (mismatch)');
google_start_url('login');
ok(google_take_state('', $w) === null && $w === 'no_state' && isset($_SESSION['g_oauth']), 'missing state refused but the saved one is kept');
$_SESSION = [];
ok(google_take_state('abc', $w) === null && $w === 'no_session', 'no session at all is reported as no_session');
google_start_url('signup');
$st = $_SESSION['g_oauth']['state'];
ok(is_array(google_take_state($st)), 'right state accepted');
ok(google_take_state($st) === null, 'state is single use');
google_start_url('login');
$_SESSION['g_oauth']['t'] = time() - 3600;
ok(google_take_state($_SESSION['g_oauth']['state'], $w) === null && $w === 'old', 'old state refused');
unlink(__DIR__ . '/../config/google.local.php');

// the app shells are recognised, also from a service worker (web view's own user agent, without our marker)
foreach (['Mozilla/5.0 ExpenseFlowApp/1.0.17', 'Mozilla/5.0 ExpenseFlowDesktop/1.0.17', 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ExpenseFlow/1.0.17 Chrome/130.0.0.0 Electron/33.2.1 Safari/537.36'] as $ua) {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    ok(google_in_app_shell(), 'app shell recognised: ' . substr($ua, -30));
}
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36';
ok(!google_in_app_shell(), 'a normal browser is not an app shell');
unset($_SERVER['HTTP_USER_AGENT']);

$conn->exec("DELETE FROM users WHERE email LIKE '%@google-test.local'");
echo $fails ? "$fails FAILED\n" : "google_signin tests: ALL PASSED\n";
exit($fails ? 1 : 0);
