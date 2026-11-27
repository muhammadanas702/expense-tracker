<?php
/**
 * Alerts for the Android app (Part 130). The app is a WebView shell, which cannot receive Web Push, so the server keeps
 * a small inbox per person and the app asks for new items every ~15 minutes (token = one phone, like the SMS token).
 * Only the hash of a token is stored. The token can do one thing: fetch that person's pending alerts.
 */
require_once __DIR__ . '/lib.php';

const APP_MAX_DEVICES = 3;
const APP_INBOX_KEEP_DAYS = 3;

function app_device_token_new(PDO $conn, int $uid, string $lang = 'en'): string
{
    push_ensure_schema($conn);
    $token = bin2hex(random_bytes(32));
    $conn->prepare('INSERT INTO app_devices (user_id, token_hash, lang) VALUES (?, ?, ?)')->execute([$uid, hash('sha256', $token), $lang === 'ur' ? 'ur' : 'en']);
    $conn->prepare('DELETE FROM app_devices WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM app_devices WHERE user_id = ? ORDER BY id DESC LIMIT ' . APP_MAX_DEVICES . ') k)')->execute([$uid, $uid]);
    return $token;
}

/** [user id, device id] for a token, or [0, 0]. Also remembers the time of this check-in. */
function app_device_user(PDO $conn, string $token): array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return [0, 0];
    }
    push_ensure_schema($conn);
    $st = $conn->prepare('SELECT id, user_id FROM app_devices WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return [0, 0];
    }
    $conn->prepare('UPDATE app_devices SET last_poll = NOW() WHERE id = ?')->execute([$r['id']]);
    return [(int) $r['user_id'], (int) $r['id']];
}

/** Puts an alert in the inbox of every person in $userIds who has the app. $make(lang) -> [title, body]. Returns how many were queued. */
function app_inbox_enqueue(PDO $conn, array $userIds, callable $make, string $url = ''): int
{
    $n = 0;
    try {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($u) => $u > 0)));
        if (!$ids) {
            return 0;
        }
        $devs = $conn->query('SELECT user_id, MAX(lang) AS lang FROM app_devices WHERE user_id IN (' . implode(',', $ids) . ') GROUP BY user_id')->fetchAll(PDO::FETCH_ASSOC);
        $cache = [];
        $ins = $conn->prepare('INSERT INTO app_inbox (user_id, title, body, url) VALUES (?,?,?,?)');
        foreach ($devs as $d) {
            $lang = $d['lang'] === 'ur' ? 'ur' : 'en';
            $cache[$lang] ??= ef_with_lang($lang, fn() => $make($lang));
            [$title, $body] = $cache[$lang];
            $ins->execute([(int) $d['user_id'], mb_substr($title, 0, 80), mb_substr($body, 0, 200), mb_substr($url, 0, 120)]);
            $n++;
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow app inbox failed: ' . $e->getMessage());
    }
    return $n;
}

/** The pending alerts of a person (oldest first, at most $limit), marked as delivered. */
function app_inbox_take(PDO $conn, int $uid, int $limit = 5): array
{
    $st = $conn->prepare('SELECT id, title, body, url FROM app_inbox WHERE user_id = ? AND delivered_at IS NULL AND created_at > NOW() - INTERVAL ' . APP_INBOX_KEEP_DAYS . ' DAY ORDER BY id ASC LIMIT ' . max(1, $limit));
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $conn->exec('UPDATE app_inbox SET delivered_at = NOW() WHERE id IN (' . implode(',', array_map(fn($r) => (int) $r['id'], $rows)) . ')');
    }
    if (random_int(1, 20) === 1) {
        $conn->exec('DELETE FROM app_inbox WHERE created_at < NOW() - INTERVAL 10 DAY LIMIT 500');
    }
    return array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'url' => $r['url']], $rows);
}
