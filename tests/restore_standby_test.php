<?php
// Part 107: backup -> encrypted -> restore into an empty database -> same data.
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../tools/restore-standby.php';
$ok = 0; $bad = 0;
function t(bool $c, string $m): void { global $ok, $bad; $c ? $ok++ : $bad++; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; }
$split = restore_split_sql("SET a=1;\n-- c; x\nINSERT INTO t VALUES ('a;b','it''s;'), (\"q;\");\nSELECT 1;");
t(count($split) === 3, 'splitter keeps ; inside quotes and skips comments (' . count($split) . ')');
[$name, $e] = backup_create($conn);
t($name !== null, 'backup created ' . ($e ?? ''));
$path = backup_dir() . '/' . $name;
$enc = $path . '.enc'; file_put_contents($enc, backup_encrypt((string) file_get_contents($path), 'pass-123456'));
$err = null;
t(restore_read_backup($enc, 'wrong-pass-1', $err) === null, 'wrong passphrase refused');
t(restore_read_backup($enc, null, $err) === null, 'missing passphrase refused');
$sql = restore_read_backup($enc, 'pass-123456', $err);
t($sql !== null, 'encrypted backup opens');
$root = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS ef_standby_test; CREATE DATABASE ef_standby_test CHARACTER SET utf8mb4');
$st = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=ef_standby_test;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
[$tables, $n] = restore_run($st, $sql);
t($tables > 10, "restored $tables tables / $n statements");
foreach (['users', 'expenses', 'income', 'shared_groups'] as $tb) {
    $a = (int) $conn->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn(); $b = (int) $st->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn();
    t($a === $b, "$tb rows equal ($a)");
}
$root->exec('DROP DATABASE ef_standby_test'); @unlink($enc); backup_delete($name);
echo "$ok passed, $bad failed\n"; exit($bad ? 1 : 0);
