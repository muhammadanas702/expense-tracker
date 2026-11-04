<?php
/**
 * Personal module helpers: payment methods and monthly repeating items.
 * This file only touches the income, expenses and recurring_items tables.
 */
require_once __DIR__ . '/../../includes/schema.php';
require_once __DIR__ . '/../../includes/CurrencyConverter.php';

function personal_payment_methods(): array
{
    return ['Cash' => 'Cash', 'Card' => 'Card', 'Bank' => 'Bank', 'Easypaisa' => 'Easypaisa', 'JazzCash' => 'JazzCash'];
}

/** Returns a valid method name, or null when empty/unknown. */
function personal_payment_method($value): ?string
{
    return is_string($value) && isset(personal_payment_methods()[$value]) ? $value : null;
}

const PERSONAL_MAX_TAGS = 5;

/**
 * Turns what the person typed ("Trip, #office work") into the stored form ",trip,office-work," (so one tag can be matched exactly).
 * Returns null for no tags, false when the input is not allowed (more than 5 tags, a tag over 20 characters, odd characters).
 */
function personal_tags_store(string $raw)
{
    $out = [];
    foreach (preg_split('/[,;\n]+/', $raw) ?: [] as $t) {
        $t = mb_strtolower(trim(ltrim(trim($t), '#')));
        $t = preg_replace('/\s+/', '-', $t);
        if ($t === '') {
            continue;
        }
        if (mb_strlen($t) > 20 || !preg_match('/^[\p{L}\p{N}_-]+$/u', $t)) {
            return false;
        }
        $out[$t] = true;
    }
    if (count($out) > PERSONAL_MAX_TAGS) {
        return false;
    }
    return $out ? ',' . implode(',', array_keys($out)) . ',' : null;
}

/** ",trip,work," -> ['trip', 'work'] */
function personal_tags_list(?string $stored): array
{
    return array_values(array_filter(explode(',', (string) $stored), fn($t) => $t !== ''));
}

/** Creates the Part 3 columns/table when the SQL file has not been run yet. */
function personal_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        foreach (['income', 'expenses'] as $t) {
            foreach (['payment_method' => 'VARCHAR(20) NULL DEFAULT NULL', 'note' => 'VARCHAR(255) NULL DEFAULT NULL', 'tags' => 'VARCHAR(150) NULL DEFAULT NULL'] as $col => $def) {
                if (!schema_has($conn, $t, $col)) {
                    $conn->exec("ALTER TABLE `$t` ADD COLUMN `$col` $def");
                    schema_has($conn, $t, $col, true);
                }
            }
        }
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `recurring_items` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `kind` VARCHAR(7) NOT NULL,
              `title` VARCHAR(100) NOT NULL,
              `amount` DECIMAL(15,2) NOT NULL,
              `currency` VARCHAR(3) NOT NULL DEFAULT 'PKR',
              `category` VARCHAR(50) NULL DEFAULT NULL,
              `payment_method` VARCHAR(20) NULL DEFAULT NULL,
              `note` VARCHAR(255) NULL DEFAULT NULL,
              `day_of_month` TINYINT UNSIGNED NOT NULL,
              `next_run` DATE NOT NULL,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_recurring_user` (`user_id`, `active`, `next_run`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `offline_imports` (
              `user_id` INT UNSIGNED NOT NULL,
              `cid` VARCHAR(32) NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`, `cid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow personal schema check failed: ' . $e->getMessage());
    }
}

/** The date a monthly item runs in the month of $ref, clamped to that month's length. */
function personal_run_date(int $day, DateTimeImmutable $ref): DateTimeImmutable
{
    $last = (int) $ref->format('t');
    return $ref->setDate((int) $ref->format('Y'), (int) $ref->format('n'), min($day, $last));
}

/** Same item, next month. */
function personal_next_run(int $day, string $current): string
{
    $first = (new DateTimeImmutable($current))->modify('first day of next month');
    return personal_run_date($day, $first)->format('Y-m-d');
}

function personal_add_recurring(PDO $conn, int $userId, string $kind, array $f): void
{
    $today = new DateTimeImmutable('today');
    $day = (int) $today->format('j');
    // The first copy is the entry being saved right now, so the next one is next month.
    $next = personal_next_run($day, $today->format('Y-m-d'));
    $conn->prepare(
        'INSERT INTO `recurring_items` (`user_id`,`kind`,`title`,`amount`,`currency`,`category`,`payment_method`,`note`,`day_of_month`,`next_run`)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$userId, $kind, $f['title'], $f['amount'], $f['currency'], $f['category'] ?? null, $f['payment_method'] ?? null, $f['note'] ?? null, $day, $next]);
}

/**
 * Creates the entries that became due. Called when a signed-in Personal page opens.
 * Each due item is claimed with an UPDATE first, so two open tabs cannot create it twice.
 * At most 12 catch-up entries per item are made in one run.
 */
function personal_run_recurring(PDO $conn, int $userId): int
{
    personal_ensure_schema($conn);
    $created = 0;
    try {
        $stmt = $conn->prepare("SELECT * FROM `recurring_items` WHERE `user_id` = ? AND `active` = 1 AND `next_run` <= ?");
        $stmt->execute([$userId, date('Y-m-d')]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $guard = 0;
            $due = $item['next_run'];
            while ($due <= date('Y-m-d') && $guard++ < 12) {
                $next = personal_next_run((int) $item['day_of_month'], $due);
                $claim = $conn->prepare('UPDATE `recurring_items` SET `next_run` = ? WHERE `id` = ? AND `next_run` = ?');
                $claim->execute([$next, $item['id'], $due]);
                if ($claim->rowCount() !== 1) {
                    break; // another request already handled this one
                }
                $pkr = round(CurrencyConverter::convert((float) $item['amount'], $item['currency'], 'PKR'), 2);
                $when = $due . ' 09:00:00';
                if ($item['kind'] === 'income') {
                    $conn->prepare('INSERT INTO `income` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`transaction_date`,`payment_method`,`note`) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$userId, $item['title'], $item['amount'], $item['currency'], $pkr, $when, $item['payment_method'], $item['note']]);
                } else {
                    $conn->prepare('INSERT INTO `expenses` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`category`,`transaction_date`,`payment_method`,`note`) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$userId, $item['title'], $item['amount'], $item['currency'], $pkr, $item['category'] ?: 'Other', $when, $item['payment_method'], $item['note']]);
                }
                $created++;
                $due = $next;
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow recurring run failed: ' . $e->getMessage());
    }
    return $created;
}

/** A client id sent by the offline queue / double-click protection: 16-32 hex characters, or null. */
function personal_client_id($v): ?string
{
    return is_string($v) && preg_match('/^[a-f0-9]{16,32}$/', $v) ? $v : null;
}

/** The time an offline entry was made on the phone, accepted only if it is within the last 14 days and not in the future. */
function personal_client_time($v, ?int $now = null): ?int
{
    $now = $now ?? time();
    if (!is_string($v) && !is_int($v)) {
        return null;
    }
    $t = (int) $v;
    return ($t >= $now - 14 * 86400 && $t <= $now + 120) ? $t : null;
}
