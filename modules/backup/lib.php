<?php
/**
 * Backups module. Writes a plain SQL export of the database (no mysqldump needed, so it works on shared hosting)
 * into storage/backups (closed to the web by .htaccess; files only leave through admin/backup-action.php).
 * A backup contains EVERY account, password hash and entry, so only trusted staff should have the "backup" permission.
 * Uploaded receipt pictures are not part of it. To restore: import the .sql file in phpMyAdmin.
 */
const BACKUP_KEEP = 10;
const BACKUP_SKIP_TABLES = ['rate_limit_hits'];
const BACKUP_NAME_RE = '/^backup-\d{8}-\d{6}-[0-9a-f]{8}\.sql(\.gz)?$/';

function backup_dir(): string
{
    return dirname(__DIR__, 2) . '/storage/backups';
}

function backup_prepare_dir(): bool
{
    $d = backup_dir();
    if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
        return false;
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    if (!is_file($d . '/index.html')) {
        @file_put_contents($d . '/index.html', '');
    }
    return is_writable($d);
}

/** Newest first: [['name','size','time'], ...] */
function backup_list(): array
{
    $out = [];
    foreach (@glob(backup_dir() . '/backup-*') ?: [] as $f) {
        $n = basename($f);
        if (preg_match(BACKUP_NAME_RE, $n) && is_file($f)) {
            $out[] = ['name' => $n, 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

function backup_valid_name($n): ?string
{
    return is_string($n) && preg_match(BACKUP_NAME_RE, $n) && is_file(backup_dir() . '/' . $n) ? $n : null;
}

/** Writes one export. Returns [name|null, error|null]. */
function backup_create(PDO $conn): array
{
    if (!backup_prepare_dir()) {
        return [null, 'The backup folder cannot be written on this server.'];
    }
    $last = backup_list()[0]['time'] ?? 0;
    if (time() - $last < 30) {
        return [null, 'A backup was just made. Please wait a moment.'];
    }
    @set_time_limit(120);
    $gz = function_exists('gzopen');
    $name = 'backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . ($gz ? '.sql.gz' : '.sql');
    $path = backup_dir() . '/' . $name;
    $h = $gz ? @gzopen($path, 'wb6') : @fopen($path, 'wb');
    if (!$h) {
        return [null, 'Could not create the backup file.'];
    }
    $w = fn(string $s) => $gz ? gzwrite($h, $s) : fwrite($h, $s);
    $unbuf = $conn->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    try {
        $w("-- ExpenseFlow backup " . date('Y-m-d H:i:s') . "\n-- Import with phpMyAdmin (Import tab). Contains private data: keep it safe.\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $conn->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            if (in_array($t, BACKUP_SKIP_TABLES, true) || !preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                continue;
            }
            $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS `$t`;\n" . $create . ";\n\n");
            $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $q = $conn->query("SELECT * FROM `$t`");
            $cols = null;
            $batch = [];
            while (($row = $q->fetch(PDO::FETCH_NUM)) !== false) {
                if ($cols === null) {
                    $cols = '`' . implode('`,`', array_map(fn($i) => $q->getColumnMeta($i)['name'], range(0, $q->columnCount() - 1))) . '`';
                }
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $conn->quote((string) $v), $row)) . ')';
                if (count($batch) >= 100) {
                    $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            $q->closeCursor();
            $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($batch) {
                $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $e) {
        error_log('ExpenseFlow backup failed: ' . $e->getMessage());
        $gz ? gzclose($h) : fclose($h);
        @unlink($path);
        $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $unbuf);
        return [null, 'The backup failed. Nothing was saved.'];
    }
    $gz ? gzclose($h) : fclose($h);
    $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $unbuf);
    @chmod($path, 0640);
    foreach (array_slice(backup_list(), BACKUP_KEEP) as $old) { // keep only the newest few
        @unlink(backup_dir() . '/' . $old['name']);
    }
    return [$name, null];
}

function backup_delete(string $name): bool
{
    $n = backup_valid_name($name);
    return $n !== null && @unlink(backup_dir() . '/' . $n);
}

function backup_size_label(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
}
