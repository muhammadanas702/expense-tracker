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

$conn->exec("DELETE FROM users WHERE email LIKE '%@google-test.local'");

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

// 6. deleting the user removes the identity
$conn->exec("DELETE FROM users WHERE id=$id1");
ok((int) $conn->query("SELECT COUNT(*) FROM user_identities WHERE user_id=$id1")->fetchColumn() === 0, 'identity removed with the account');

// 7. start URL carries state + PKCE; state is single use and expires
file_put_contents(__DIR__ . '/../config/google.local.php', "<?php return ['client_id'=>'$cid','client_secret'=>'s3cret'];");
$_SESSION = [];
$url = google_start_url('login');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
ok(strpos($url, 'https://accounts.google.com/o/oauth2/v2/auth?') === 0, 'goes to Google');
ok(($q['code_challenge_method'] ?? '') === 'S256' && strlen($q['code_challenge'] ?? '') === 43, 'PKCE S256 challenge');
ok(($q['scope'] ?? '') === 'openid email profile' && ($q['client_id'] ?? '') === $cid, 'scope and client id');
ok(google_take_state('wrong') === null, 'wrong state refused');
google_start_url('signup');
$st = $_SESSION['g_oauth']['state'];
ok(is_array(google_take_state($st)), 'right state accepted');
ok(google_take_state($st) === null, 'state is single use');
google_start_url('login');
$_SESSION['g_oauth']['t'] = time() - 3600;
ok(google_take_state($_SESSION['g_oauth']['state']) === null, 'old state refused');
unlink(__DIR__ . '/../config/google.local.php');

$conn->exec("DELETE FROM users WHERE email LIKE '%@google-test.local'");
echo $fails ? "$fails FAILED\n" : "google_signin tests: ALL PASSED\n";
exit($fails ? 1 : 0);
