<?php
require_once __DIR__ . '/../../includes/schema.php';
require_once __DIR__ . '/../../includes/CurrencyConverter.php';
/**
 * Budgets module. Touches only budgets / budget_alerts (and reads expenses, user_settings).
 * A budget is a monthly limit for one expense category, in the currency the person chose. Spending is the sum of that
 * category's expenses in the calendar month, each in the PKR value saved with it (so rates changing later do not move it).
 */
const BUDGET_MAX_AMOUNT = 1000000000;
const BUDGET_MAX_PER_USER = 40;

function budgets_ensure_schema(PDO $conn): void
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
        // No foreign keys on purpose (some hosts refuse them); budgets_on_user_deleted() cleans up.
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `budgets` (
              `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `user_id`    INT UNSIGNED  NOT NULL,
              `category`   VARCHAR(50)   NOT NULL,
              `amount`     DECIMAL(15,2) NOT NULL,
              `currency`   VARCHAR(3)    NOT NULL DEFAULT 'PKR',
              `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_budget_user_category` (`user_id`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `budget_alerts` (
              `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`  INT UNSIGNED NOT NULL,
              `category` VARCHAR(50)  NOT NULL,
              `ym`       CHAR(7)      NOT NULL,
              `level`    TINYINT      NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_budget_alert` (`user_id`, `category`, `ym`, `level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('ef_schema_mark')) {
            ef_schema_mark(__FUNCTION__, __FILE__);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow budgets schema check failed: ' . $e->getMessage());
    }
}

/** Validates the form. Returns [clean values|null, error|null]. $allowed = names of the categories people may pick. */
function budgets_validate(array $in, array $allowed): array
{
    $cat = trim((string) ($in['category'] ?? ''));
    if ($cat === '' || !in_array($cat, $allowed, true)) {
        return [null, 'Please choose a category.'];
    }
    $raw = str_replace(',', '', trim((string) ($in['amount'] ?? '')));
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0 || (float) $raw > BUDGET_MAX_AMOUNT) {
        return [null, 'Enter a monthly limit above zero (for example 20000).'];
    }
    $cur = function_exists('normalize_currency') ? normalize_currency((string) ($in['currency'] ?? '')) : null;
    if ($cur === null) {
        return [null, 'Please choose a currency.'];
    }
    return [['category' => $cat, 'amount' => round((float) $raw, 2), 'currency' => $cur], null];
}

/** Adds or changes the budget of a category. Returns false when the person already has the maximum number of budgets. */
function budgets_save(PDO $conn, int $userId, array $v): bool
{
    budgets_ensure_schema($conn);
    $n = $conn->prepare('SELECT COUNT(*) FROM budgets WHERE user_id = ? AND category <> ?');
    $n->execute([$userId, $v['category']]);
    if ((int) $n->fetchColumn() >= BUDGET_MAX_PER_USER) {
        return false;
    }
    $conn->prepare('INSERT INTO budgets (user_id, category, amount, currency) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency)')
        ->execute([$userId, $v['category'], $v['amount'], $v['currency']]);
    return true;
}

function budgets_delete(PDO $conn, int $userId, int $id): void
{
    budgets_ensure_schema($conn);
    $conn->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

/** Month bounds [from, to) for 'YYYY-MM' (default: this month). */
function budgets_month(?string $ym = null): array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $ym && preg_match('/^\d{4}-\d{2}$/', $ym) ? $ym : date('Y-m')) ?: new DateTimeImmutable('first day of this month');
    return [$d->format('Y-m-d 00:00:00'), $d->modify('+1 month')->format('Y-m-d 00:00:00'), $d->format('Y-m')];
}

/**
 * Every budget of the person with this month's spending.
 * Each item: id, category, amount, currency, spent (in the budget's currency), left, pct (0..), level (ok|warn|over).
 */
function budgets_status(PDO $conn, int $userId, ?string $ym = null): array
{
    budgets_ensure_schema($conn);
    [$from, $to] = budgets_month($ym);
    $st = $conn->prepare('SELECT id, category, amount, currency FROM budgets WHERE user_id = ? ORDER BY category');
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    $sp = $conn->prepare('SELECT category, amount, currency, amount_pkr FROM expenses WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ?');
    $sp->execute([$userId, $from, $to]);
    $pkr = [];
    foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $c = trim((string) $e['category']);
        $pkr[$c] = ($pkr[$c] ?? 0) + CurrencyConverter::expenseInPkr($e);
    }
    $out = [];
    foreach ($rows as $r) {
        $spent = CurrencyConverter::convert($pkr[$r['category']] ?? 0, 'PKR', $r['currency']);
        $limit = (float) $r['amount'];
        $pct = $limit > 0 ? ($spent / $limit) * 100 : 0;
        $out[] = ['id' => (int) $r['id'], 'category' => $r['category'], 'amount' => $limit, 'currency' => $r['currency'],
            'spent' => round($spent, 2), 'left' => round($limit - $spent, 2), 'pct' => round($pct, 1),
            'level' => $pct >= 100 ? 'over' : ($pct >= 80 ? 'warn' : 'ok')];
    }
    return $out;
}

/**
 * Call after an expense was added or changed. Sends ONE notification when the category crosses 80% and ONE at 100% per month
 * (unless the person turned notifications off). Never throws; returns the level that was just announced (0, 80 or 100).
 */
function budgets_check_alerts(PDO $conn, int $userId, string $category): int
{
    try {
        $item = null;
        foreach (budgets_status($conn, $userId) as $b) {
            if ($b['category'] === trim($category)) {
                $item = $b;
            }
        }
        if (!$item || $item['pct'] < 80) {
            return 0;
        }
        $level = $item['pct'] >= 100 ? 100 : 80;
        $ym = date('Y-m');
        $ins = $conn->prepare('INSERT IGNORE INTO budget_alerts (user_id, category, ym, level) VALUES (?,?,?,?)');
        // 100% also counts as having passed 80%, so crossing straight to 100% gives one message, not two.
        if ($level === 100) {
            $ins->execute([$userId, $item['category'], $ym, 80]);
        }
        $ins->execute([$userId, $item['category'], $ym, $level]);
        if ($ins->rowCount() !== 1 || !function_exists('activity_record')) {
            return 0;
        }
        activity_record($conn, 0, $userId, $level === 100 ? 'budget_100' : 'budget_80',
            ['title' => $item['category'], 'amount_minor' => (int) round($item['pct']), 'by' => 0], [$userId]);
        return $level;
    } catch (Throwable $e) {
        error_log('ExpenseFlow budget alert failed: ' . $e->getMessage());
        return 0;
    }
}

/** Account deleted: remove the person's budgets and alerts (there is no foreign key). */
function budgets_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        budgets_ensure_schema($conn);
        $conn->prepare('DELETE FROM budgets WHERE user_id = ?')->execute([$userId]);
        $conn->prepare('DELETE FROM budget_alerts WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow budgets cleanup: ' . $e->getMessage());
    }
}
