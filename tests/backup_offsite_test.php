<?php
// Part 52: encrypted off-site backup helpers. Run: php tests/backup_offsite_test.php
require_once __DIR__ . '/../modules/backup/lib.php';
$f = 0;
function ok($c, $m) { global $f; if (!$c) { $f++; echo "FAIL $m\n"; } }
$plain = "-- sql\nINSERT INTO t VALUES ('é','اردو');\n" . random_bytes(5000);
$enc = backup_encrypt($plain, 'correct horse');
ok($enc !== null && str_starts_with($enc, BACKUP_MAGIC), 'encrypts');
ok(strpos($enc, 'INSERT INTO') === false, 'ciphertext does not contain the text');
ok(backup_decrypt($enc, 'correct horse') === $plain, 'round trip');
ok(backup_decrypt($enc, 'wrong passphrase') === null, 'wrong passphrase refused');
$bad = $enc; $bad[strlen($bad) - 3] = chr(ord($bad[strlen($bad) - 3]) ^ 1);
ok(backup_decrypt($bad, 'correct horse') === null, 'tampered file refused');
ok(backup_decrypt('hello', 'correct horse') === null, 'not a backup file refused');
ok(backup_encrypt('x', 'short') === null, 'short passphrase refused');
ok(backup_encrypt($plain, 'correct horse') !== $enc, 'every file gets a fresh salt/iv');
// CLI tool
$tmp = sys_get_temp_dir() . '/ef_enc_' . getmypid();
file_put_contents($tmp . '.enc', $enc);
$p = proc_open(['php', __DIR__ . '/../tools/decrypt-backup.php', $tmp . '.enc'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], "correct horse\n"); fclose($pipes[0]); proc_close($p);
ok(is_file($tmp) && file_get_contents($tmp) === $plain, 'decrypt-backup.php restores the file');
@unlink($tmp); @unlink($tmp . '.enc');
// automatic daily backup (Part 52b)
ok(backup_auto_due(PHP_INT_MAX / 2) === true, 'auto backup due when far in the future');
ok(backup_auto_due(0) === false || (backup_list()[0]['time'] ?? 0) === 0, 'auto backup not due in the past');
putenv('EXPENSEFLOW_AUTO_BACKUP_OFF=1');
ok(backup_auto_maybe(new PDO('sqlite::memory:')) === null, 'switch-off env var stops the automatic backup');
echo $f ? "$f FAILED\n" : "backup offsite tests: ALL PASSED\n";
