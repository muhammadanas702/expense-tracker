<?php
/**
 * Web Push without libraries: VAPID (RFC 8292) + message encryption aes128gcm (RFC 8291), PHP openssl + curl.
 * Tables: push_subscriptions, push_vapid (one row with the server key). Everything is best-effort: a failed push never breaks a page.
 */
require_once __DIR__ . '/../../includes/schema.php';

const PUSH_MAX_PER_USER = 5;
const PUSH_MAX_PER_CALL = 12;

function push_ensure_schema(PDO $conn): void
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
        $conn->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `endpoint_hash` CHAR(64) NOT NULL,
            `endpoint` VARCHAR(600) NOT NULL,
            `p256dh` VARCHAR(150) NOT NULL,
            `auth` VARCHAR(60) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_push_endpoint` (`endpoint_hash`),
            KEY `idx_push_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->exec("CREATE TABLE IF NOT EXISTS `push_vapid` (
            `id` TINYINT UNSIGNED NOT NULL,
            `private_pem` TEXT NOT NULL,
            `public_key` VARCHAR(120) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ExpenseFlow push schema failed: ' . $e->getMessage());
    }
}

function push_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function push_unb64u(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/** New P-256 key pair: [private PEM, public key as 65 raw bytes]. */
function push_new_keypair(): array
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) {
        throw new RuntimeException('no EC support');
    }
    openssl_pkey_export($k, $pem);
    $d = openssl_pkey_get_details($k)['ec'];
    return [$pem, "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
}

/** Public key (65 raw bytes) as an OpenSSL key resource. */
function push_public_key(string $raw)
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") {
        return false;
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/** The server's VAPID key: [private PEM, public key base64url]; created on first use. */
function push_vapid(PDO $conn): array
{
    push_ensure_schema($conn);
    $r = $conn->query('SELECT private_pem, public_key FROM push_vapid WHERE id = 1')->fetch(PDO::FETCH_NUM);
    if ($r) {
        return [$r[0], $r[1]];
    }
    [$pem, $pub] = push_new_keypair();
    $conn->prepare('INSERT IGNORE INTO push_vapid (id, private_pem, public_key) VALUES (1, ?, ?)')->execute([$pem, push_b64u($pub)]);
    $r = $conn->query('SELECT private_pem, public_key FROM push_vapid WHERE id = 1')->fetch(PDO::FETCH_NUM);
    return [$r[0], $r[1]];
}

/** DER ECDSA signature -> 64 raw bytes (r || s). */
function push_der_to_raw(string $der): string
{
    $o = 3;
    $rl = ord($der[$o]);
    $r = substr($der, $o + 1, $rl);
    $o += 1 + $rl + 1;
    $sl = ord($der[$o]);
    $s = substr($der, $o + 1, $sl);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}

function push_vapid_header(string $endpoint, string $pem, string $pubB64, string $subject): string
{
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $in = push_b64u('{"typ":"JWT","alg":"ES256"}') . '.' . push_b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $subject]));
    openssl_sign($in, $sig, $pem, OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $in . '.' . push_b64u(push_der_to_raw($sig)) . ', k=' . $pubB64;
}

/** RFC 8291 aes128gcm body for one subscription (p256dh = 65 raw bytes, auth = 16 raw bytes). Optional fixed salt/key are for tests. */
function push_encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, ?array $asKey = null): string
{
    $ua = push_public_key($uaPublic);
    if (!$ua || strlen($authSecret) !== 16 || strlen($payload) > 3800) {
        throw new InvalidArgumentException('bad subscription or payload');
    }
    [$asPem, $asPub] = $asKey ?? push_new_keypair();
    $secret = openssl_pkey_derive($ua, openssl_pkey_get_private($asPem), 32);
    if ($secret === false) {
        throw new RuntimeException('ecdh failed');
    }
    $salt = $salt ?? random_bytes(16);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPub, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** Sends one encrypted push. Returns the HTTP status (0 = network failure). EXPENSEFLOW_PUSH_SINK=<file> writes the request there instead (tests). */
function push_send_one(PDO $conn, array $sub, string $json): int
{
    try {
        [$pem, $pub] = push_vapid($conn);
        $base = (string) ($GLOBALS['base_url'] ?? '');
        $subject = str_starts_with($base, 'https://') ? $base : 'mailto:noreply@expenseflow.invalid';
        $body = push_encrypt($json, push_unb64u($sub['p256dh']), push_unb64u($sub['auth']));
        $headers = ['Authorization: ' . push_vapid_header($sub['endpoint'], $pem, $pub, $subject), 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: normal'];
        if (($sink = getenv('EXPENSEFLOW_PUSH_SINK')) !== false && $sink !== '') {
            file_put_contents($sink, json_encode(['endpoint' => $sub['endpoint'], 'headers' => $headers, 'body' => base64_encode($body)]) . "\n", FILE_APPEND);
            return 201;
        }
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    } catch (Throwable $e) {
        error_log('ExpenseFlow push send failed: ' . $e->getMessage());
        return 0;
    }
}

/** Sends a notification to every device of the given users. Dead subscriptions (404/410) are removed. Returns how many were accepted. */
function push_notify(PDO $conn, array $userIds, string $title, string $body, string $url = ''): int
{
    $ok = 0;
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($u) => $u > 0)));
        if (!$ids) {
            return 0;
        }
        push_ensure_schema($conn);
        $subs = $conn->query('SELECT id, user_id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id IN (' . implode(',', $ids) . ') LIMIT ' . PUSH_MAX_PER_CALL)->fetchAll(PDO::FETCH_ASSOC);
        $json = json_encode(['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 200), 'url' => $url], JSON_UNESCAPED_UNICODE);
        foreach ($subs as $s) {
            $code = push_send_one($conn, $s, $json);
            if ($code >= 200 && $code < 300) {
                $ok++;
            } elseif ($code === 404 || $code === 410) {
                $conn->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([(int) $s['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow push notify failed: ' . $e->getMessage());
    }
    return $ok;
}

/** Pushes the activity event $eventId to $users, worded from each person's point of view. */
function push_for_event(PDO $conn, int $eventId, array $users): void
{
    if (!$users || !function_exists('activity_describe')) {
        return;
    }
    try {
        $q = $conn->prepare('SELECT e.*, a.name AS actor_name, t.name AS target_name FROM activity_events e LEFT JOIN users a ON a.id = e.actor_id LEFT JOIN users t ON t.id = e.target_id WHERE e.id = ?');
        $q->execute([$eventId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $personal = (int) $row['group_id'] === 0;
        foreach ($users as $u) {
            [, $text, $amt] = activity_describe($row, (int) $u);
            push_notify($conn, [(int) $u], 'ExpenseFlow', trim($text . ($amt !== '' ? ' · ' . $amt : '')), $personal ? 'reminders.php' : 'activity.php');
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow push event failed: ' . $e->getMessage());
    }
}

function push_valid_endpoint(string $e): bool
{
    $p = parse_url($e);
    return strlen($e) <= 600 && $p && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && str_contains($p['host'], '.') && !filter_var($p['host'], FILTER_VALIDATE_IP);
}

function push_subscribe(PDO $conn, int $userId, string $endpoint, string $p256dh, string $auth): bool
{
    push_ensure_schema($conn);
    if (!push_valid_endpoint($endpoint) || strlen(push_unb64u($p256dh)) !== 65 || strlen(push_unb64u($auth)) !== 16) {
        return false;
    }
    $n = $conn->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND endpoint_hash <> ?');
    $h = hash('sha256', $endpoint);
    $n->execute([$userId, $h]);
    if ((int) $n->fetchColumn() >= PUSH_MAX_PER_USER) {
        $conn->prepare('DELETE FROM push_subscriptions WHERE user_id = ? ORDER BY id ASC LIMIT 1')->execute([$userId]); // oldest device makes room
    }
    $conn->prepare('INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)')
        ->execute([$userId, $h, $endpoint, $p256dh, $auth]);
    return true;
}

function push_unsubscribe(PDO $conn, int $userId, string $endpoint): void
{
    push_ensure_schema($conn);
    $conn->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')->execute([$userId, hash('sha256', $endpoint)]);
}

function push_device_count(PDO $conn, int $userId): int
{
    push_ensure_schema($conn);
    $q = $conn->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
    $q->execute([$userId]);
    return (int) $q->fetchColumn();
}

/** Account deletion hook. */
function push_on_user_deleted(PDO $conn, int $userId): void
{
    push_ensure_schema($conn);
    $conn->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$userId]);
}

/** Runs $job after the page was sent (so a slow push service never slows a page); at once on the command line (tests). */
function push_defer(callable $job): void
{
    if (PHP_SAPI === 'cli') {
        $job();
        return;
    }
    register_shutdown_function(function () use ($job) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $job();
    });
}

/** Called by activity_record(): pushes the new event to the people who got a bell notification. Never throws. */
function push_after_event(PDO $conn, int $eventId, array $users): void
{
    if ($users) {
        push_defer(fn() => push_for_event($conn, $eventId, $users));
    }
}
