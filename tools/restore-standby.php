<?php
/**
 * Puts an ExpenseFlow backup into an EMPTY database on a standby host (Part 107).
 *
 * Usage (on your computer or on the new host, command line only):
 *   php tools/restore-standby.php <backup file> --host=HOST --db=NAME --user=USER [--write-config]
 *   <backup file> can be .sql, .sql.gz, or the encrypted .enc from the off-site repository.
 * Secrets are asked on the keyboard (database password, and the passphrase for .enc files).
 * --write-config also writes config/db.local.php so the uploaded site uses this database.
 * Nothing is deleted except tables with the same names inside the target database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../modules/backup/lib.php';

/** Splits SQL text into statements; ';' inside quotes or after "--" comments does not split. */
function restore_split_sql(string $sql): array
{
    $out = []; $cur = ''; $q = ''; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== '') {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) { $cur .= $sql[++$i]; continue; }
            if ($c === $q) { if ($i + 1 < $n && $sql[$i + 1] === $q) { $cur .= $sql[++$i]; } else { $q = ''; } }
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\n")) {
            $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e; continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === ';') { if (trim($cur) !== '') { $out[] = trim($cur); } $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') { $out[] = trim($cur); }
    return $out;
}

/** Reads a backup file (plain, gz or encrypted) and returns the SQL text, or null with $err set. */
function restore_read_backup(string $file, ?string $pass, ?string &$err): ?string
{
    $raw = (string) file_get_contents($file);
    if (str_ends_with($file, '.enc')) {
        if ($pass === null) { $err = 'This file is encrypted: a passphrase is needed.'; return null; }
        $raw = backup_decrypt($raw, $pass);
        if ($raw === null) { $err = 'Wrong passphrase or damaged file.'; return null; }
    }
    if (substr($raw, 0, 2) === "\x1f\x8b") {
        $raw = @gzdecode($raw);
        if ($raw === false) { $err = 'The backup is damaged (cannot unzip).'; return null; }
    }
    if (strpos($raw, 'CREATE TABLE') === false) { $err = 'This does not look like an ExpenseFlow backup.'; return null; }
    return $raw;
}

/** Runs the statements on $pdo. Returns [tables created, rows inserted-statements run]. */
function restore_run(PDO $pdo, string $sql): array
{
    $tables = 0; $stmts = 0;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (restore_split_sql($sql) as $st) {
        $pdo->exec($st);
        $stmts++;
        if (stripos($st, 'CREATE TABLE') === 0) { $tables++; }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return [$tables, $stmts];
}

if (realpath($argv[0] ?? '') !== realpath(__FILE__)) { return; } // loaded by a test: functions only

function ask(string $label): string { fwrite(STDERR, $label . ': '); return rtrim((string) fgets(STDIN), "\r\n"); }
$file = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opt[$m[1]] = $m[2] ?? '1'; } }
if (!is_file($file) || empty($opt['host']) || empty($opt['db']) || empty($opt['user'])) {
    fwrite(STDERR, "Usage: php tools/restore-standby.php <backup> --host=H --db=NAME --user=U [--write-config]\n"); exit(1);
}
$pass = str_ends_with($file, '.enc') ? ask('Backup passphrase') : null;
$err = null;
$sql = restore_read_backup($file, $pass, $err);
if ($sql === null) { fwrite(STDERR, "$err\n"); exit(2); }
$dbpass = ask('Database password');
try {
    $pdo = new PDO('mysql:host=' . $opt['host'] . ';dbname=' . $opt['db'] . ';charset=utf8mb4', $opt['user'], $dbpass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    [$tables, $stmts] = restore_run($pdo, $sql);
} catch (Throwable $e) { fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n"); exit(3); }
$users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
fwrite(STDERR, "Done: $tables tables, $stmts statements, $users users.\n");
if (isset($opt['write-config'])) {
    $cfg = "<?php\nreturn " . var_export(['host' => $opt['host'], 'dbname' => $opt['db'], 'username' => $opt['user'], 'password' => $dbpass], true) . ";\n";
    file_put_contents(__DIR__ . '/../config/db.local.php', $cfg);
    fwrite(STDERR, "Wrote config/db.local.php\n");
}
