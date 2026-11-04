<?php
/**
 * Opens an encrypted ExpenseFlow backup on YOUR computer.
 * Usage:  php tools/decrypt-backup.php backup-....sql.gz.enc [output-file]
 * The passphrase is asked on the keyboard. The result is the original .sql.gz / .sql file.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../modules/backup/lib.php';
$in = $argv[1] ?? '';
if (!is_file($in)) { fwrite(STDERR, "Usage: php tools/decrypt-backup.php file.enc [output]\n"); exit(1); }
$out = $argv[2] ?? preg_replace('/\.enc$/', '', $in);
if ($out === $in) { $out .= '.dec'; }
fwrite(STDERR, 'Passphrase: ');
$pass = rtrim((string) fgets(STDIN), "\r\n");
$plain = backup_decrypt((string) file_get_contents($in), $pass);
if ($plain === null) { fwrite(STDERR, "Wrong passphrase or damaged file.\n"); exit(2); }
file_put_contents($out, $plain);
fwrite(STDERR, "Saved $out\n");
