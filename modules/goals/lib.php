<?php
require_once __DIR__ . '/../../includes/schema.php';
/**
 * Savings goals. Tables: savings_goals (the goal) and goal_entries (money put in or taken out, so every change can be seen).
 * Amounts live in the goal's own currency; goals never touch income/expense totals.
 */
const GOALS_MAX_PER_USER = 20;
const GOALS_MAX_AMOUNT = 1000000000;

function goals_ensure_schema(PDO $conn): void
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
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `savings_goals` (
              `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `user_id`    INT UNSIGNED  NOT NULL,
              `name`       VARCHAR(60)   NOT NULL,
              `target`     DECIMAL(15,2) NOT NULL,
              `currency`   VARCHAR(3)    NOT NULL DEFAULT 'PKR',
              `deadline`   DATE          NULL DEFAULT NULL,
              `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_goal_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `goal_entries` (
              `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `goal_id`    INT UNSIGNED  NOT NULL,
              `user_id`    INT UNSIGNED  NOT NULL,
              `amount`     DECIMAL(15,2) NOT NULL,
              `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_entry_goal` (`goal_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('ef_schema_mark')) {
            ef_schema_mark(__FUNCTION__, __FILE__);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow goals schema check failed: ' . $e->getMessage());
    }
}

function goals_amount(string $raw): ?float
{
    $raw = str_replace(',', '', trim($raw));
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0 || (float) $raw > GOALS_MAX_AMOUNT) {
        return null;
    }
    return round((float) $raw, 2);
}

/** Validates the "new goal" form. Returns [clean|null, error|null]. */
function goals_validate(array $in, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 60) {
        return [null, 'Give the goal a name (60 characters at most).'];
    }
    $target = goals_amount((string) ($in['target'] ?? ''));
    if ($target === null) {
        return [null, 'Enter a target above zero (for example 100000).'];
    }
    $cur = function_exists('normalize_currency') ? normalize_currency((string) ($in['currency'] ?? '')) : null;
    if ($cur === null) {
        return [null, 'Please choose a currency.'];
    }
    $deadline = null;
    $d = trim((string) ($in['deadline'] ?? ''));
    if ($d !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d || $dt <= $today || $dt > $today->modify('+30 years')) {
            return [null, 'The deadline must be a future date (or leave it empty).'];
        }
        $deadline = $d;
    }
    return [['name' => $name, 'target' => $target, 'currency' => $cur, 'deadline' => $deadline], null];
}

/** Returns the new goal id, or 0 when the person already has the maximum number of goals. */
function goals_create(PDO $conn, int $userId, array $v): int
{
    goals_ensure_schema($conn);
    $n = $conn->prepare('SELECT COUNT(*) FROM savings_goals WHERE user_id = ?');
    $n->execute([$userId]);
    if ((int) $n->fetchColumn() >= GOALS_MAX_PER_USER) {
        return 0;
    }
    $conn->prepare('INSERT INTO savings_goals (user_id, name, target, currency, deadline) VALUES (?,?,?,?,?)')
        ->execute([$userId, $v['name'], $v['target'], $v['currency'], $v['deadline']]);
    return (int) $conn->lastInsertId();
}

/**
 * Puts money in ($sign = 1) or takes it out (-1). Taking out more than is saved is refused.
 * Returns null on success or an error text.
 */
function goals_move(PDO $conn, int $userId, int $goalId, string $rawAmount, int $sign): ?string
{
    goals_ensure_schema($conn);
    $amount = goals_amount($rawAmount);
    if ($amount === null) {
        return 'Enter an amount above zero.';
    }
    $conn->beginTransaction();
    try {
        $g = $conn->prepare('SELECT id FROM savings_goals WHERE id = ? AND user_id = ? FOR UPDATE');
        $g->execute([$goalId, $userId]);
        if (!$g->fetchColumn()) {
            $conn->rollBack();
            return 'That goal was not found.';
        }
        $s = $conn->prepare('SELECT COALESCE(SUM(amount),0) FROM goal_entries WHERE goal_id = ?');
        $s->execute([$goalId]);
        $saved = (float) $s->fetchColumn();
        if ($sign < 0 && $amount > $saved + 0.004) {
            $conn->rollBack();
            return 'You only have ' . number_format($saved, 2) . ' saved in this goal.';
        }
        if ($sign > 0 && $saved + $amount > GOALS_MAX_AMOUNT) {
            $conn->rollBack();
            return 'That amount is too large.';
        }
        $conn->prepare('INSERT INTO goal_entries (goal_id, user_id, amount) VALUES (?,?,?)')->execute([$goalId, $userId, $sign * $amount]);
        $conn->commit();
        return null;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ExpenseFlow goal move failed: ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

function goals_delete(PDO $conn, int $userId, int $goalId): void
{
    goals_ensure_schema($conn);
    $own = $conn->prepare('SELECT id FROM savings_goals WHERE id = ? AND user_id = ?');
    $own->execute([$goalId, $userId]);
    if ($own->fetchColumn()) {
        $conn->prepare('DELETE FROM goal_entries WHERE goal_id = ?')->execute([$goalId]);
        $conn->prepare('DELETE FROM savings_goals WHERE id = ?')->execute([$goalId]);
    }
}

/** Whole months from today until the deadline, at least 1 (null without a deadline or when it has passed). */
function goals_months_left(?string $deadline, ?DateTimeImmutable $today = null): ?int
{
    if ($deadline === null || $deadline === '') {
        return null;
    }
    $today = $today ?? new DateTimeImmutable('today');
    $d = new DateTimeImmutable($deadline);
    if ($d <= $today) {
        return null;
    }
    $diff = $today->diff($d);
    return max(1, $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0));
}

/**
 * All goals of the person: saved, pct (0..100+), left, done, months_left, per_month (to finish by the deadline), overdue.
 */
function goals_list(PDO $conn, int $userId, ?DateTimeImmutable $today = null): array
{
    goals_ensure_schema($conn);
    $q = $conn->prepare('SELECT g.id, g.name, g.target, g.currency, g.deadline, COALESCE(SUM(e.amount),0) AS saved
        FROM savings_goals g LEFT JOIN goal_entries e ON e.goal_id = g.id WHERE g.user_id = ? GROUP BY g.id ORDER BY g.id DESC');
    $q->execute([$userId]);
    $today = $today ?? new DateTimeImmutable('today');
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $target = (float) $r['target'];
        $saved = round((float) $r['saved'], 2);
        $left = round(max(0, $target - $saved), 2);
        $done = $saved >= $target;
        $months = goals_months_left($r['deadline'], $today);
        $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'target' => $target, 'currency' => $r['currency'], 'deadline' => $r['deadline'],
            'saved' => $saved, 'left' => $left, 'done' => $done, 'pct' => $target > 0 ? round($saved / $target * 100, 1) : 0,
            'months_left' => $months, 'per_month' => (!$done && $months) ? round($left / $months, 2) : null,
            'overdue' => !$done && $r['deadline'] !== null && new DateTimeImmutable($r['deadline']) <= $today];
    }
    return $out;
}

function goals_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        goals_ensure_schema($conn);
        $conn->prepare('DELETE FROM goal_entries WHERE user_id = ?')->execute([$userId]);
        $conn->prepare('DELETE FROM savings_goals WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow goals cleanup: ' . $e->getMessage());
    }
}
