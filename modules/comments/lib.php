<?php
/**
 * Comments module helpers. Touches only comments_items (and reads shared_* / users).
 * Never throws into the caller: reading returns empty on failure.
 */
const COMMENTS_MAX_LEN = 500;
const COMMENTS_PER_EXPENSE = 200;

function comments_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `comments_items` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `expense_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `body` VARCHAR(500) NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_comments_expense` (`expense_id`, `id`),
              KEY `idx_comments_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow comments schema check failed: ' . $e->getMessage());
    }
}

/** Comments of several expenses at once: expense_id => list (oldest first). */
function comments_for_expenses(PDO $conn, array $expenseIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $expenseIds))));
    $out = [];
    if (!$ids) {
        return $out;
    }
    try {
        comments_ensure_schema($conn);
        $q = $conn->query(
            'SELECT c.id, c.expense_id, c.user_id, c.body, c.created_at, TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age_s, u.name
             FROM comments_items c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.expense_id IN (' . implode(',', $ids) . ') ORDER BY c.id ASC'
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['expense_id']][] = $r;
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow comments read failed: ' . $e->getMessage());
    }
    return $out;
}

/** Adds a comment. Returns the new id or null with $error set. Caller has checked group membership. */
function comments_add(PDO $conn, int $groupId, int $expenseId, int $userId, string $body, ?string &$error = null): ?int
{
    $text = clean_text($body, COMMENTS_MAX_LEN);
    if ($text === null) {
        $error = 'Write a comment (up to ' . COMMENTS_MAX_LEN . ' characters).';
        return null;
    }
    comments_ensure_schema($conn);
    $n = $conn->prepare('SELECT COUNT(*) FROM comments_items WHERE expense_id = ?');
    $n->execute([$expenseId]);
    if ((int) $n->fetchColumn() >= COMMENTS_PER_EXPENSE) {
        $error = 'This expense has too many comments.';
        return null;
    }
    $conn->prepare('INSERT INTO comments_items (group_id, expense_id, user_id, body) VALUES (?,?,?,?)')->execute([$groupId, $expenseId, $userId, $text]);
    return (int) $conn->lastInsertId();
}

/** True when this user posted exactly this text on this expense in the last 15 seconds (double tap, refresh). */
function comments_is_duplicate(PDO $conn, int $expenseId, int $userId, string $body): bool
{
    $text = clean_text($body, COMMENTS_MAX_LEN);
    if ($text === null) {
        return false;
    }
    comments_ensure_schema($conn);
    $q = $conn->prepare('SELECT 1 FROM comments_items WHERE expense_id = ? AND user_id = ? AND body = ? AND created_at >= (NOW() - INTERVAL 15 SECOND) LIMIT 1');
    $q->execute([$expenseId, $userId, $text]);
    return (bool) $q->fetchColumn();
}

function comments_get(PDO $conn, int $id, int $groupId): ?array
{
    comments_ensure_schema($conn);
    $q = $conn->prepare('SELECT * FROM comments_items WHERE id = ? AND group_id = ?');
    $q->execute([$id, $groupId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function comments_delete(PDO $conn, int $id): void
{
    $conn->prepare('DELETE FROM comments_items WHERE id = ?')->execute([$id]);
}

/** Cleanup hooks (called by Shared). */
function comments_on_expense_deleted(PDO $conn, int $expenseId): void
{
    try {
        comments_ensure_schema($conn);
        $conn->prepare('DELETE FROM comments_items WHERE expense_id = ?')->execute([$expenseId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow comments cleanup failed: ' . $e->getMessage());
    }
}

function comments_on_group_deleted(PDO $conn, int $groupId): void
{
    try {
        comments_ensure_schema($conn);
        $conn->prepare('DELETE FROM comments_items WHERE group_id = ?')->execute([$groupId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow comments cleanup failed: ' . $e->getMessage());
    }
}

function comments_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        comments_ensure_schema($conn);
        $conn->prepare('DELETE FROM comments_items WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow comments cleanup failed: ' . $e->getMessage());
    }
}
