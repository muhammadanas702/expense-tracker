<?php
/**
 * Security module. Touches only security_events and ip_blocks.
 * Everything that runs on the sign-in pages is best-effort and fails open: a bookkeeping problem
 * must never lock real people out. A block only stops signing in, signing up and password-reset
 * emails from that address; the rest of the site stays reachable.
 */
const SECURITY_EVENT_KINDS = [
    'login_fail' => 'Wrong password or email',
    'login_locked' => 'Locked out (too many tries)',
    'signup_limit' => 'Sign-up limit reached',
    'ip_refused' => 'Refused (blocked address)',
];
const SECURITY_DURATIONS = ['1h' => ['1 hour', 3600], '1d' => ['1 day', 86400], '7d' => ['7 days', 604800], '30d' => ['30 days', 2592000], 'forever' => ['Until removed', 0]];
const SECURITY_BLOCK_MAX = 200;

function security_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `security_events` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `kind` VARCHAR(14) NOT NULL,
              `ip` VARCHAR(45) NOT NULL DEFAULT '',
              `who` VARCHAR(60) NOT NULL DEFAULT '',
              `created_at` DATETIME NOT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_sec_time` (`created_at`),
              KEY `idx_sec_ip` (`ip`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `ip_blocks` (
              `ip` VARCHAR(45) NOT NULL,
              `reason` VARCHAR(100) NOT NULL DEFAULT '',
              `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
              `created_at` DATETIME NOT NULL,
              `expires_at` DATETIME NULL DEFAULT NULL,
              PRIMARY KEY (`ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow security schema check failed: ' . $e->getMessage());
    }
}

/** "someone@example.com" -> "s***@example.com" so staff can recognise an attack without reading private emails. */
function security_mask_email(string $email): string
{
    $email = trim($email);
    if (!preg_match('/^([^@\s]{1,64})@([^@\s]{1,120})$/', $email, $m)) {
        return $email === '' ? '' : 'invalid input';
    }
    return mb_substr($m[1], 0, 1, 'UTF-8') . '***@' . $m[2];
}

/** Records one event (best effort). */
function security_event(PDO $conn, string $kind, string $who = ''): void
{
    try {
        if (!isset(SECURITY_EVENT_KINDS[$kind])) {
            return;
        }
        security_ensure_schema($conn);
        $conn->prepare('INSERT INTO security_events (kind, ip, who, created_at) VALUES (?,?,?,NOW())')
            ->execute([$kind, substr(client_ip(), 0, 45), mb_substr(security_mask_email($who), 0, 60, 'UTF-8')]);
        if (random_int(1, 50) === 1) {
            $conn->exec('DELETE FROM security_events WHERE created_at < (NOW() - INTERVAL 30 DAY)');
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow security event failed: ' . $e->getMessage());
    }
}

/** Is this visitor's address blocked right now? Fails open. Records a "refused" event when it is. */
function security_ip_blocked(PDO $conn): bool
{
    try {
        security_ensure_schema($conn);
        $s = $conn->prepare('SELECT 1 FROM ip_blocks WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW())');
        $s->execute([client_ip()]);
        if ($s->fetchColumn()) {
            security_event($conn, 'ip_refused');
            return true;
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow ip block check failed: ' . $e->getMessage());
    }
    return false;
}

function security_valid_ip($ip): ?string
{
    $ip = is_string($ip) ? trim($ip) : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? strtolower($ip) : null;
}

/** [true|null, error|null]. Never lets staff block the address they are using right now. */
function security_block(PDO $conn, string $ipRaw, string $reason, string $duration, int $by): array
{
    security_ensure_schema($conn);
    $ip = security_valid_ip($ipRaw);
    if ($ip === null) {
        return [null, 'That is not a valid IP address.'];
    }
    if ($ip === strtolower(client_ip())) {
        return [null, 'You cannot block the address you are using right now.'];
    }
    if (!isset(SECURITY_DURATIONS[$duration])) {
        $duration = '1d';
    }
    $why = clean_text($reason, 100) ?? '';
    $now = (int) $conn->query('SELECT COUNT(*) FROM ip_blocks WHERE expires_at IS NULL OR expires_at > NOW()')->fetchColumn();
    $has = $conn->prepare('SELECT 1 FROM ip_blocks WHERE ip = ?');
    $has->execute([$ip]);
    if ($now >= SECURITY_BLOCK_MAX && !$has->fetchColumn()) {
        return [null, 'You can have up to ' . SECURITY_BLOCK_MAX . ' active blocks. Remove some first.'];
    }
    $secs = SECURITY_DURATIONS[$duration][1];
    $conn->prepare('REPLACE INTO ip_blocks (ip, reason, created_by, created_at, expires_at) VALUES (?,?,?,NOW(),' . ($secs ? 'NOW() + INTERVAL ' . (int) $secs . ' SECOND' : 'NULL') . ')')
        ->execute([$ip, $why, $by]);
    return [true, null];
}

function security_unblock(PDO $conn, string $ipRaw): bool
{
    $ip = security_valid_ip($ipRaw);
    if ($ip === null) {
        return false;
    }
    $d = $conn->prepare('DELETE FROM ip_blocks WHERE ip = ?');
    $d->execute([$ip]);
    return $d->rowCount() > 0;
}

function security_active_blocks(PDO $conn): array
{
    security_ensure_schema($conn);
    $conn->exec('DELETE FROM ip_blocks WHERE expires_at IS NOT NULL AND expires_at <= NOW()');
    return $conn->query('SELECT b.ip, b.reason, b.created_at, b.expires_at, u.name AS by_name FROM ip_blocks b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
}

/** Counts for the tiles: failed/locked in the last 24h and 7d, active blocks, blocked accounts. */
function security_stats(PDO $conn): array
{
    security_ensure_schema($conn);
    $c = fn(string $sql) => (int) $conn->query($sql)->fetchColumn();
    return [
        'fail24' => $c("SELECT COUNT(*) FROM security_events WHERE kind='login_fail' AND created_at > NOW() - INTERVAL 1 DAY"),
        'locked24' => $c("SELECT COUNT(*) FROM security_events WHERE kind='login_locked' AND created_at > NOW() - INTERVAL 1 DAY"),
        'fail7' => $c("SELECT COUNT(*) FROM security_events WHERE kind='login_fail' AND created_at > NOW() - INTERVAL 7 DAY"),
        'blocks' => $c('SELECT COUNT(*) FROM ip_blocks WHERE expires_at IS NULL OR expires_at > NOW()'),
        'users' => schema_has($conn, 'users', 'blocked') ? $c('SELECT COUNT(*) FROM users WHERE blocked = 1') : 0,
    ];
}

/** Addresses with the most failures in the last 7 days. */
function security_top_ips(PDO $conn, int $limit = 8): array
{
    security_ensure_schema($conn);
    $s = $conn->prepare("SELECT ip, COUNT(*) AS n, MAX(created_at) AS last_at FROM security_events WHERE kind IN ('login_fail','login_locked') AND ip <> '' AND created_at > NOW() - INTERVAL 7 DAY GROUP BY ip ORDER BY n DESC, last_at DESC LIMIT " . (int) $limit);
    $s->execute();
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function security_recent(PDO $conn, string $kind, int $page, int $per = 25): array
{
    security_ensure_schema($conn);
    $where = isset(SECURITY_EVENT_KINDS[$kind]) ? 'WHERE kind = ?' : '';
    $p = $where ? [$kind] : [];
    $t = $conn->prepare("SELECT COUNT(*) FROM security_events $where");
    $t->execute($p);
    $total = (int) $t->fetchColumn();
    $s = $conn->prepare("SELECT id, kind, ip, who, created_at FROM security_events $where ORDER BY id DESC LIMIT " . (int) $per . ' OFFSET ' . max(0, ($page - 1) * $per));
    $s->execute($p);
    return [$s->fetchAll(PDO::FETCH_ASSOC), $total];
}
