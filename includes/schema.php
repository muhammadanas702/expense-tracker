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

/** Adds missing tables/columns that the current code relies on. Runs once per session. */
function ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['schema_checked_v2'])) {
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['schema_checked_v2'] = true;
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow schema check failed: ' . $e->getMessage());
    }
}
