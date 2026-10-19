<?php
/**
 * Announcements module. Touches only announcements and announcement_dismissed.
 * Everything that runs on normal pages is best-effort: a failure never breaks the page.
 */
const ANNOUNCE_TITLE_MAX = 80;
const ANNOUNCE_BODY_MAX = 400;
const ANNOUNCE_LEVELS = ['info' => 'Information', 'good' => 'Good news', 'warn' => 'Important'];
const ANNOUNCE_SHOW_MAX = 2;

function announce_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `announcements` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `title` VARCHAR(80) NOT NULL,
              `body` VARCHAR(400) NOT NULL DEFAULT '',
              `level` VARCHAR(5) NOT NULL DEFAULT 'info',
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              `starts_at` DATETIME NULL DEFAULT NULL,
              `ends_at` DATETIME NULL DEFAULT NULL,
              `created_by` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_announce_active` (`active`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `announcement_dismissed` (
              `user_id` INT UNSIGNED NOT NULL,
              `announcement_id` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`user_id`, `announcement_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow announce schema check failed: ' . $e->getMessage());
    }
}

/** Parses a <input type="datetime-local"> value (or '') to 'Y-m-d H:i:s' / null. Returns false when invalid. */
function announce_parse_when(string $v)
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v) ?: DateTime::createFromFormat('Y-m-d H:i:s', $v);
    return $d ? $d->format('Y-m-d H:i:s') : false;
}

/**
 * Validates the staff form. Returns [data|null, error|null].
 * data: title, body, level, active, starts_at, ends_at
 */
function announce_validate(array $in): array
{
    $title = clean_text((string) ($in['title'] ?? ''), ANNOUNCE_TITLE_MAX);
    if ($title === null) {
        return [null, 'Write a short title (up to ' . ANNOUNCE_TITLE_MAX . ' characters).'];
    }
    $raw = trim((string) ($in['body'] ?? ''));
    $body = $raw === '' ? '' : clean_multiline($raw, ANNOUNCE_BODY_MAX);
    if ($body === null) {
        return [null, 'The message can be up to ' . ANNOUNCE_BODY_MAX . ' characters.'];
    }
    $level = isset(ANNOUNCE_LEVELS[$in['level'] ?? '']) ? $in['level'] : 'info';
    $s = announce_parse_when((string) ($in['starts_at'] ?? ''));
    $e = announce_parse_when((string) ($in['ends_at'] ?? ''));
    if ($s === false || $e === false) {
        return [null, 'The date or time is not valid.'];
    }
    if ($s !== null && $e !== null && $e <= $s) {
        return [null, 'The end must be after the start.'];
    }
    return [['title' => $title, 'body' => $body, 'level' => $level, 'active' => !empty($in['active']) ? 1 : 0, 'starts_at' => $s, 'ends_at' => $e], null];
}

function announce_save(PDO $conn, ?int $id, array $d, int $by): int
{
    announce_ensure_schema($conn);
    if ($id) {
        $conn->prepare('UPDATE announcements SET title=?, body=?, level=?, active=?, starts_at=?, ends_at=? WHERE id=?')
            ->execute([$d['title'], $d['body'], $d['level'], $d['active'], $d['starts_at'], $d['ends_at'], $id]);
        return $id;
    }
    $conn->prepare('INSERT INTO announcements (title, body, level, active, starts_at, ends_at, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$d['title'], $d['body'], $d['level'], $d['active'], $d['starts_at'], $d['ends_at'], $by]);
    return (int) $conn->lastInsertId();
}

function announce_get(PDO $conn, int $id): ?array
{
    announce_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM announcements WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function announce_delete(PDO $conn, int $id): void
{
    $conn->prepare('DELETE FROM announcement_dismissed WHERE announcement_id = ?')->execute([$id]);
    $conn->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
}

/** 'live' | 'scheduled' | 'ended' | 'off' */
function announce_state(array $a): string
{
    if (!(int) $a['active']) {
        return 'off';
    }
    $now = date('Y-m-d H:i:s');
    if (!empty($a['starts_at']) && $a['starts_at'] > $now) {
        return 'scheduled';
    }
    if (!empty($a['ends_at']) && $a['ends_at'] <= $now) {
        return 'ended';
    }
    return 'live';
}

function announce_list(PDO $conn): array
{
    announce_ensure_schema($conn);
    $rows = $conn->query('SELECT a.*, (SELECT COUNT(*) FROM announcement_dismissed d WHERE d.announcement_id = a.id) AS dismissed FROM announcements a ORDER BY a.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['state'] = announce_state($r);
    }
    return $rows;
}

/** Live announcements this user has not dismissed (newest first). */
function announce_for_user(PDO $conn, int $userId): array
{
    try {
        announce_ensure_schema($conn);
        $s = $conn->prepare(
            'SELECT a.id, a.title, a.body, a.level FROM announcements a
             WHERE a.active = 1 AND (a.starts_at IS NULL OR a.starts_at <= NOW()) AND (a.ends_at IS NULL OR a.ends_at > NOW())
               AND NOT EXISTS (SELECT 1 FROM announcement_dismissed d WHERE d.announcement_id = a.id AND d.user_id = ?)
             ORDER BY a.id DESC LIMIT ' . ANNOUNCE_SHOW_MAX
        );
        $s->execute([$userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function announce_dismiss(PDO $conn, int $userId, int $id): void
{
    announce_ensure_schema($conn);
    $conn->prepare('INSERT IGNORE INTO announcement_dismissed (user_id, announcement_id) VALUES (?, ?)')->execute([$userId, $id]);
}

/** Banner markup for the pages (empty string when there is nothing to show). */
function announce_banner_html(PDO $conn, int $userId, string $csrf, string $action): string
{
    $out = '';
    foreach (announce_for_user($conn, $userId) as $a) {
        $lvl = isset(ANNOUNCE_LEVELS[$a['level']]) ? $a['level'] : 'info';
        $out .= '<div class="ann ' . $lvl . '" role="note"><div><b>' . e($a['title']) . '</b>'
            . ($a['body'] !== '' ? '<span>' . nl2br(e($a['body'])) . '</span>' : '') . '</div>'
            . '<form method="post" action="' . e($action) . '"><input type="hidden" name="csrf_token" value="' . e($csrf) . '"><input type="hidden" name="id" value="' . (int) $a['id'] . '">'
            . '<button type="submit" aria-label="Dismiss">×</button></form></div>';
    }
    return $out;
}

/** Account deleted: forget which announcements this person closed (announcements stay; they belong to staff). */
function announce_on_user_deleted(PDO $conn, int $userId): void
{
    $conn->prepare('DELETE FROM announcement_dismissed WHERE user_id = ?')->execute([$userId]);
}
