<?php
/**
 * Activity module helpers: events ("Ali added Dinner"), per-user notifications, feed queries.
 * Touches only activity_* tables (and reads users / shared_members / shared_groups / user_settings).
 * Everything is best-effort: a failure here never breaks the action that triggered it.
 */
const ACTIVITY_KINDS = [
    'expense_add', 'expense_edit', 'expense_delete', 'settle', 'settle_delete',
    'member_join', 'member_leave', 'member_remove', 'group_create', 'group_rename', 'comment',
];

function activity_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `activity_events` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `actor_id` INT UNSIGNED NOT NULL,
              `kind` VARCHAR(20) NOT NULL,
              `title` VARCHAR(100) NULL DEFAULT NULL,
              `amount_minor` BIGINT UNSIGNED NULL DEFAULT NULL,
              `currency` VARCHAR(3) NULL DEFAULT NULL,
              `target_id` INT UNSIGNED NULL DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_activity_events_group` (`group_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `activity_notifications` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `event_id` INT UNSIGNED NOT NULL,
              `read_at` TIMESTAMP NULL DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_activity_notifications` (`user_id`, `event_id`),
              KEY `idx_activity_notifications_user` (`user_id`, `read_at`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow activity schema check failed: ' . $e->getMessage());
    }
}

/**
 * Records an event and notifies the given users (the person who did it, $d['by'] or the actor, is never notified; users who switched
 * notifications off in Profile are skipped). Returns the event id, or 0 on failure.
 * $d: title, amount_minor, currency, target_id
 */
function activity_record(PDO $conn, int $groupId, int $actorId, string $kind, array $d = [], array $notify = []): int
{
    if (!in_array($kind, ACTIVITY_KINDS, true)) {
        return 0;
    }
    try {
        activity_ensure_schema($conn);
        $title = isset($d['title']) ? mb_substr((string) $d['title'], 0, 100) : null;
        $conn->prepare('INSERT INTO activity_events (group_id,actor_id,kind,title,amount_minor,currency,target_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$groupId, $actorId, $kind, $title, $d['amount_minor'] ?? null, $d['currency'] ?? null, $d['target_id'] ?? null]);
        $eid = (int) $conn->lastInsertId();
        $users = array_values(array_unique(array_filter(array_map('intval', $notify), fn($u) => $u > 0 && $u !== (int) ($d['by'] ?? $actorId))));
        if ($users) {
            $in = implode(',', $users); // integers only
            $off = [];
            try {
                $off = array_map('intval', $conn->query("SELECT user_id FROM user_settings WHERE notifications = 0 AND user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {
                // settings table not created yet: everybody gets notifications
            }
            $ins = $conn->prepare('INSERT IGNORE INTO activity_notifications (user_id, event_id) VALUES (?, ?)');
            foreach (array_diff($users, $off) as $u) {
                $ins->execute([$u, $eid]);
            }
        }
        return $eid;
    } catch (Throwable $e) {
        error_log('ExpenseFlow activity record failed: ' . $e->getMessage());
        return 0;
    }
}

function activity_unread_count(PDO $conn, int $userId): int
{
    try {
        // No schema check here: this runs on every page. A missing table just means "0 new".
        $q = $conn->prepare('SELECT COUNT(*) FROM activity_notifications WHERE user_id = ? AND read_at IS NULL');
        $q->execute([$userId]);
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Newest notifications of a user with the event, group and people names. */
function activity_notifications(PDO $conn, int $userId, int $limit = 50): array
{
    $q = $conn->prepare(
        'SELECT n.id AS nid, n.read_at, e.*, TIMESTAMPDIFF(SECOND, e.created_at, NOW()) AS age_s, DATEDIFF(CURDATE(), DATE(e.created_at)) AS day_diff, g.name AS group_name, a.name AS actor_name, t.name AS target_name
         FROM activity_notifications n
         JOIN activity_events e ON e.id = n.event_id
         LEFT JOIN shared_groups g ON g.id = e.group_id
         LEFT JOIN users a ON a.id = e.actor_id
         LEFT JOIN users t ON t.id = e.target_id
         WHERE n.user_id = ? ORDER BY n.id DESC LIMIT ' . (int) $limit
    );
    $q->execute([$userId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function activity_mark_read(PDO $conn, int $userId): void
{
    $conn->prepare('UPDATE activity_notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL')->execute([$userId]);
}

/**
 * Feed of the groups the user belongs to (only what happened after they joined).
 * $groupId limits it to one group (must be one of the user's groups; otherwise empty).
 */
function activity_feed(PDO $conn, int $userId, int $groupId = 0, int $limit = 100): array
{
    $sql = 'SELECT e.*, TIMESTAMPDIFF(SECOND, e.created_at, NOW()) AS age_s, DATEDIFF(CURDATE(), DATE(e.created_at)) AS day_diff, g.name AS group_name, a.name AS actor_name, t.name AS target_name
            FROM activity_events e
            JOIN shared_members m ON m.group_id = e.group_id AND m.user_id = ? AND e.created_at >= m.joined_at
            JOIN shared_groups g ON g.id = e.group_id
            LEFT JOIN users a ON a.id = e.actor_id
            LEFT JOIN users t ON t.id = e.target_id
            WHERE 1 = 1';
    $args = [$userId];
    if ($groupId > 0) {
        $sql .= ' AND e.group_id = ?';
        $args[] = $groupId;
    }
    $q = $conn->prepare($sql . ' ORDER BY e.id DESC LIMIT ' . (int) $limit);
    $q->execute($args);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** [icon, sentence, amount text] for one event row, from the viewer's point of view. */
function activity_describe(array $r, int $viewerId): array
{
    $who = (int) $r['actor_id'] === $viewerId ? 'You' : ($r['actor_name'] ?? 'Former member');
    $target = (int) ($r['target_id'] ?? 0) === $viewerId ? 'you' : ($r['target_name'] ?? 'Former member');
    $t = $r['title'] !== null ? '“' . $r['title'] . '”' : '';
    $amt = $r['amount_minor'] !== null ? ($r['currency'] ?? '') . ' ' . number_format((int) $r['amount_minor'] / 100, 2) : '';
    switch ($r['kind']) {
        case 'expense_add':    return ['food', "$who added $t", $amt];
        case 'expense_edit':   return ['pen', "$who changed $t", $amt];
        case 'expense_delete': return ['tr', "$who deleted $t", $amt];
        case 'settle':         return ['wal', "$who paid $target", $amt];
        case 'settle_delete':  return ['tr', "$who deleted a payment to $target", $amt];
        case 'member_join':    return ['users', "$who joined the group", ''];
        case 'member_leave':   return ['out', "$who left the group", ''];
        case 'member_remove':  return ['users', "$who removed $target from the group", ''];
        case 'group_create':   return ['users', "$who created the group", ''];
        case 'group_rename':   return ['pen', "$who renamed the group", ''];
        case 'comment':        return ['msg', "$who commented on $t", ''];
    }
    return ['act', "$who did something", ''];
}

/** Human time from the age in seconds (computed by the database, so time zones cannot disturb it). */
function activity_ago(int $seconds, string $ts): string
{
    if ($seconds < 60) { return 'just now'; }
    if ($seconds < 3600) { return floor($seconds / 60) . ' min ago'; }
    if ($seconds < 86400) { return floor($seconds / 3600) . ' h ago'; }
    if ($seconds < 7 * 86400) { return floor($seconds / 86400) . ' d ago'; }
    return date('d M Y', strtotime($ts));
}

/** Cleanup hooks. */
function activity_on_group_deleted(PDO $conn, int $groupId): void
{
    try {
        activity_ensure_schema($conn);
        $conn->prepare('DELETE FROM activity_notifications WHERE event_id IN (SELECT id FROM activity_events WHERE group_id = ?)')->execute([$groupId]);
        $conn->prepare('DELETE FROM activity_events WHERE group_id = ?')->execute([$groupId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow activity cleanup failed: ' . $e->getMessage());
    }
}

function activity_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        activity_ensure_schema($conn);
        $conn->prepare('DELETE FROM activity_notifications WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow activity cleanup failed: ' . $e->getMessage());
    }
}
