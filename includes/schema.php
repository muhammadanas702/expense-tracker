<?php
/**
 * Small, safe schema helpers.
 *
 * schema.sql is the source of truth for a fresh install. ensure_schema() only
 * adds things that newer code needs when an older database is still in use
 * (for example the live site), so deploying new code never breaks login.
 */

/** True when $table has a column called $column. Result is cached per request. */
function schema_has(PDO $conn, string $table, string $column, bool $refresh = false): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if ($refresh || !array_key_exists($key, $cache)) {
        try {
            $stmt = $conn->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
            $stmt->execute([$column]);
            $cache[$key] = (bool) $stmt->fetch();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/**
 * Fast path for the self-healing schema checks. Each check runs CREATE/ALTER statements, which cost several
 * database round trips on every request. After one clean run the check is remembered in storage/schema
 * (keyed by function + the file's last-change time), so a deploy of new code re-runs it exactly once.
 * Delete the storage/schema folder to force every check to run again.
 */
function ef_schema_ok(string $name, string $file): bool
{
    if (getenv('EXPENSEFLOW_NO_SCHEMA_CACHE')) {
        return false;
    }
    $dir = __DIR__ . '/../storage/schema';
    $mark = $dir . '/' . $name . '-' . (int) @filemtime($file) . '.ok';
    if (is_file($mark)) {
        return true;
    }
    static $queued = [];
    if (isset($queued[$mark])) {
        return false;
    }
    $queued[$mark] = true;
    register_shutdown_function(static function () use ($dir, $mark, $name) {
        if (error_get_last() !== null && in_array(error_get_last()['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        foreach (glob($dir . '/' . $name . '-*.ok') ?: [] as $old) {
            @unlink($old);
        }
        @file_put_contents($mark, date('c'));
    });
    return false;
}

/** Adds missing tables/columns that the current code relies on. Runs once per session. */
function ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['schema_checked_v3'])) {
        return;
    }

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `rate_limit_hits` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `bucket` VARCHAR(40) NOT NULL,
                `key_hash` CHAR(64) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_bucket_key_time` (`bucket`, `key_hash`, `created_at`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!schema_has($conn, 'income', 'currency')) {
            $conn->exec("ALTER TABLE `income` ADD COLUMN `currency` VARCHAR(3) NOT NULL DEFAULT 'PKR'");
            schema_has($conn, 'income', 'currency', true);
        }
        if (!schema_has($conn, 'expenses', 'amount_pkr')) {
            $conn->exec('ALTER TABLE `expenses` ADD COLUMN `amount_pkr` DECIMAL(15,2) NULL DEFAULT NULL');
            schema_has($conn, 'expenses', 'amount_pkr', true);
        }

        // Part 11b: blocked accounts cannot log in.
        if (!schema_has($conn, 'users', 'blocked')) {
            $conn->exec('ALTER TABLE `users` ADD COLUMN `blocked` TINYINT(1) NOT NULL DEFAULT 0');
            schema_has($conn, 'users', 'blocked', true);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['schema_checked_v3'] = true;
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow schema check failed: ' . $e->getMessage());
    }
}
