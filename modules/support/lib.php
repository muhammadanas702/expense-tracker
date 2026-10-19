<?php
/**
 * Support module helpers. Touches only support_tickets and support_messages (and reads users).
 * Statuses: open = waiting for staff, pending = staff answered and waits for the user, closed.
 */
const SUPPORT_SUBJECT_MAX = 100;
const SUPPORT_BODY_MAX = 2000;
const SUPPORT_MAX_OPEN_PER_USER = 5;
const SUPPORT_MAX_MESSAGES = 100;
const SUPPORT_CATEGORIES = ['account' => 'My account', 'shared' => 'Groups and splits', 'bug' => 'Something is broken', 'idea' => 'Idea or feedback', 'other' => 'Other'];
const SUPPORT_STATUSES = ['open' => 'Open', 'pending' => 'Answered', 'closed' => 'Closed'];

function support_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `support_tickets` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `subject` VARCHAR(100) NOT NULL,
              `category` VARCHAR(12) NOT NULL DEFAULT 'other',
              `status` VARCHAR(8) NOT NULL DEFAULT 'open',
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_support_user` (`user_id`, `id`),
              KEY `idx_support_status` (`status`, `updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `support_messages` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `ticket_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `is_staff` TINYINT(1) NOT NULL DEFAULT 0,
              `body` VARCHAR(2000) NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_support_msg_ticket` (`ticket_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow support schema check failed: ' . $e->getMessage());
    }
}

/** Opens a ticket with its first message. Returns the ticket id, or null with $error set. */
function support_create(PDO $conn, int $userId, string $subject, string $category, string $body, ?string &$error = null): ?int
{
    support_ensure_schema($conn);
    $subject = clean_text($subject, SUPPORT_SUBJECT_MAX);
    $text = clean_multiline($body, SUPPORT_BODY_MAX);
    if ($subject === null) {
        $error = 'Write a short subject (up to ' . SUPPORT_SUBJECT_MAX . ' characters).';
        return null;
    }
    if ($text === null) {
        $error = 'Describe your problem (up to ' . SUPPORT_BODY_MAX . ' characters).';
        return null;
    }
    if (!isset(SUPPORT_CATEGORIES[$category])) {
        $category = 'other';
    }
    $n = $conn->prepare("SELECT COUNT(*) FROM support_tickets WHERE user_id = ? AND status <> 'closed'");
    $n->execute([$userId]);
    if ((int) $n->fetchColumn() >= SUPPORT_MAX_OPEN_PER_USER) {
        $error = 'You already have ' . SUPPORT_MAX_OPEN_PER_USER . ' open requests. Please wait for an answer or close one first.';
        return null;
    }
    $conn->beginTransaction();
    try {
        $conn->prepare('INSERT INTO support_tickets (user_id, subject, category, status) VALUES (?,?,?,?)')
            ->execute([$userId, $subject, $category, 'open']);
        $id = (int) $conn->lastInsertId();
        $conn->prepare('INSERT INTO support_messages (ticket_id, user_id, is_staff, body) VALUES (?,?,0,?)')
            ->execute([$id, $userId, $text]);
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('ExpenseFlow support create failed: ' . $e->getMessage());
        $error = 'Could not send your request. Please try again.';
        return null;
    }
}

/** One ticket, only if it belongs to this user. */
function support_ticket_for_user(PDO $conn, int $id, int $userId): ?array
{
    support_ensure_schema($conn);
    $s = $conn->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?');
    $s->execute([$id, $userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** One ticket for staff, with the author's name and email. */
function support_ticket_get(PDO $conn, int $id): ?array
{
    support_ensure_schema($conn);
    $s = $conn->prepare('SELECT t.*, u.name AS user_name, u.email AS user_email FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function support_messages(PDO $conn, int $ticketId): array
{
    support_ensure_schema($conn);
    $s = $conn->prepare('SELECT m.id, m.user_id, m.is_staff, m.body, m.created_at, u.name FROM support_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.ticket_id = ? ORDER BY m.id ASC');
    $s->execute([$ticketId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adds a reply. A staff reply marks the ticket "pending" (answered), a user reply marks it "open"
 * again. Returns true or false with $error set. The caller has checked who may reply.
 */
function support_reply(PDO $conn, array $ticket, int $userId, bool $isStaff, string $body, ?string &$error = null): bool
{
    $text = clean_multiline($body, SUPPORT_BODY_MAX);
    if ($text === null) {
        $error = 'Write a message (up to ' . SUPPORT_BODY_MAX . ' characters).';
        return false;
    }
    $n = $conn->prepare('SELECT COUNT(*) FROM support_messages WHERE ticket_id = ?');
    $n->execute([(int) $ticket['id']]);
    if ((int) $n->fetchColumn() >= SUPPORT_MAX_MESSAGES) {
        $error = 'This conversation is full. Please open a new request.';
        return false;
    }
    $conn->prepare('INSERT INTO support_messages (ticket_id, user_id, is_staff, body) VALUES (?,?,?,?)')
        ->execute([(int) $ticket['id'], $userId, $isStaff ? 1 : 0, $text]);
    $conn->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$isStaff ? 'pending' : 'open', (int) $ticket['id']]);
    return true;
}

function support_set_status(PDO $conn, int $ticketId, string $status): bool
{
    if (!isset(SUPPORT_STATUSES[$status])) {
        return false;
    }
    $conn->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $ticketId]);
    return true;
}

/** The user's own tickets, newest activity first. */
function support_user_list(PDO $conn, int $userId): array
{
    support_ensure_schema($conn);
    $s = $conn->prepare('SELECT id, subject, category, status, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 50');
    $s->execute([$userId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Staff inbox: [rows, total]. $status = open|pending|closed|'' (all). */
function support_inbox(PDO $conn, string $status, string $q, int $page, int $per): array
{
    support_ensure_schema($conn);
    $where = [];
    $params = [];
    if (isset(SUPPORT_STATUSES[$status])) {
        $where[] = 't.status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[] = '(t.subject LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $c = $conn->prepare("SELECT COUNT(*) FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id $w");
    $c->execute($params);
    $total = (int) $c->fetchColumn();
    $page = max(1, min($page, max(1, (int) ceil($total / $per))));
    $s = $conn->prepare("SELECT t.id, t.subject, t.category, t.status, t.created_at, t.updated_at, u.name AS user_name FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id $w
        ORDER BY (t.status = 'open') DESC, t.updated_at DESC, t.id DESC LIMIT " . (int) $per . ' OFFSET ' . (($page - 1) * (int) $per));
    $s->execute($params);
    return [$s->fetchAll(PDO::FETCH_ASSOC), $total, $page];
}

/** ['open' => n, 'pending' => n, 'closed' => n] */
function support_counts(PDO $conn): array
{
    support_ensure_schema($conn);
    $out = ['open' => 0, 'pending' => 0, 'closed' => 0];
    foreach ($conn->query('SELECT status, COUNT(*) c FROM support_tickets GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($out[$r['status']])) {
            $out[$r['status']] = (int) $r['c'];
        }
    }
    return $out;
}

/** Account deleted: its tickets and messages go too (staff replies in those tickets with them). */
function support_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        support_ensure_schema($conn);
        $conn->prepare('DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM (SELECT id FROM support_tickets WHERE user_id = ?) x)')->execute([$userId]);
        $conn->prepare('DELETE FROM support_tickets WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow support cleanup failed: ' . $e->getMessage());
    }
}

/** "5 min ago" style label for a timestamp string. */
function support_ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    return date('d M Y', strtotime($ts));
}

/** Status pill (user and staff pages). */
function support_pill(string $status): string
{
    $cls = ['open' => 'rs', 'pending' => 'tb2', 'closed' => 'tu'][$status] ?? 'tu';
    return '<span class="tag ' . $cls . '">' . e(SUPPORT_STATUSES[$status] ?? $status) . '</span>';
}
