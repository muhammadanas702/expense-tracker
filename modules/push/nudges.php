<?php
/**
 * Phone nudges (Part 129), sent through Web Push. No cron job is needed: the uptime monitor's ping (health.php?db=1)
 * and normal page visits call push_tick(), which does the work at most once every 30 minutes and only in the daytime.
 *   1. Debt reminder: someone who still owes money in a group or with a friend gets ONE message every 2 days.
 *   2. "We miss you": someone who has not opened the app for 3, 7, 14 and then 30 days gets a gentle message (4 at most,
 *      the count starts again the next time they open the app).
 * Only people with Phone alerts turned on (a saved device) and "Notifications" on in Settings get anything.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../../includes/i18n.php';
foreach (['shared/lib.php', 'activity/lib.php'] as $f) {
    if (is_file(__DIR__ . '/../' . $f)) {
        require_once __DIR__ . '/../' . $f;
    }
}

const PUSH_TICK_BATCH = 10;

/** Remembers that $userId used the app (at most one write every 30 minutes per session). */
function push_touch_seen(PDO $conn, int $userId): void
{
    if ($userId <= 0 || (($_SESSION['ef_seen_at'] ?? 0) > time() - 1800)) {
        return;
    }
    $_SESSION['ef_seen_at'] = time();
    try {
        push_ensure_schema($conn);
        $conn->prepare('INSERT INTO push_nudges (user_id, last_seen, idle_stage) VALUES (?, NOW(), 0) ON DUPLICATE KEY UPDATE last_seen = NOW(), idle_stage = 0')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow push seen failed: ' . $e->getMessage());
    }
}

/** What a person owes: [count of people, biggest amount (minor), its currency, its person's name]. */
function push_debt_summary(PDO $conn, int $userId): ?array
{
    if (!function_exists('shared_group_pairs')) {
        return null;
    }
    $g = $conn->prepare('SELECT group_id FROM shared_members WHERE user_id = ?');
    $g->execute([$userId]);
    $groups = array_map('intval', $g->fetchAll(PDO::FETCH_COLUMN));
    if (!$groups) {
        return null;
    }
    $people = [];
    $best = null;
    foreach (shared_group_pairs($conn, $groups) as $byCur) {
        foreach ($byCur as $cur => $list) {
            foreach ($list as $p) {
                if ((int) $p['from'] !== $userId || (int) $p['amount'] <= 0) {
                    continue;
                }
                $people[(int) $p['to']] = true;
                if ($best === null || (int) $p['amount'] > $best['amount']) {
                    $best = ['amount' => (int) $p['amount'], 'cur' => (string) $cur, 'to' => (int) $p['to']];
                }
            }
        }
    }
    if ($best === null) {
        return null;
    }
    $n = $conn->prepare('SELECT name FROM users WHERE id = ?');
    $n->execute([$best['to']]);
    return ['count' => count($people), 'amount' => $best['amount'], 'cur' => $best['cur'], 'name' => (string) ($n->fetchColumn() ?: '')];
}

function push_debt_words(array $d): array
{
    $money = shared_money($d['amount'], $d['cur']);
    $name = $d['name'] !== '' ? $d['name'] : t('your friend');
    if ($d['count'] <= 1) {
        return [t('A small reminder'), t('You still owe %s %s. Settle up when you can.', $name, $money)];
    }
    return [t('A small reminder'), t('You still owe %d people. The biggest is %s: %s. Settle up when you can.', (string) $d['count'], $name, $money)];
}

function push_idle_words(int $stage): array
{
    switch ($stage) {
        case 1:  return [t('How is your day going?'), t('Did you spend anything today? Add it in a few seconds so your month stays accurate.')];
        case 2:  return [t('Your expenses miss you'), t('It has been a week. Open ExpenseFlow and catch up. Your friends may also have added shared bills.')];
        case 3:  return [t('Two weeks without an entry'), t('A quick catch-up keeps your budget and your balances correct. It takes a minute.')];
        default: return [t('We are still here for you'), t('Whenever you are ready, ExpenseFlow has your records safe. Open it to see where you stand.')];
    }
}

/** Does the whole job once. Returns [debt messages sent, idle messages sent]. Safe to call often. */
function push_tick(PDO $conn, bool $force = false): array
{
    $sent = [0, 0];
    try {
        push_ensure_schema($conn);
        if (!$force && !push_in_window($conn)) {
            return $sent; // outside the hours chosen in Admin > Notifications (default 9:00-22:00 Pakistan time): never wake anybody up
        }
        $idleDays = push_idle_days($conn);
        // one run per 30 minutes, even when many requests arrive together
        $conn->exec("INSERT IGNORE INTO push_nudges (user_id, last_seen) VALUES (0, '2000-01-01 00:00:00')");
        $lock = $conn->prepare('UPDATE push_nudges SET last_seen = NOW() WHERE user_id = 0 AND last_seen < NOW() - INTERVAL 30 MINUTE');
        $lock->execute();
        if (!$force && $lock->rowCount() === 0) {
            return $sent;
        }
        $conn->exec('INSERT IGNORE INTO push_nudges (user_id, last_seen) SELECT user_id, MIN(created_at) FROM (SELECT user_id, created_at FROM push_subscriptions UNION ALL SELECT user_id, created_at FROM app_devices) d GROUP BY user_id');
        $on = "u.id NOT IN (SELECT user_id FROM user_settings WHERE notifications = 0)";

        // 1. debt reminders
        $debtCut = max(1, min(30, (int) push_cfg($conn, 'push_debt_days')));
        $rows = push_cfg_on($conn, 'push_debt') ? $conn->query("SELECT n.user_id FROM push_nudges n JOIN users u ON u.id = n.user_id WHERE n.user_id > 0 AND $on
            AND (EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.user_id = n.user_id) OR EXISTS (SELECT 1 FROM app_devices a WHERE a.user_id = n.user_id))
            AND (n.debt_at IS NULL OR n.debt_at < NOW() - INTERVAL $debtCut DAY)
            AND (n.debt_check_at IS NULL OR n.debt_check_at < NOW() - INTERVAL 6 HOUR)
            ORDER BY n.debt_check_at IS NOT NULL, n.debt_check_at LIMIT " . PUSH_TICK_BATCH)->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($rows as $uid) {
            $uid = (int) $uid;
            $d = push_debt_summary($conn, $uid);
            if ($d && push_notify_i18n($conn, [$uid], fn() => push_debt_words($d), 'shared/index.php') > 0) {
                $conn->prepare('UPDATE push_nudges SET debt_at = NOW(), debt_check_at = NOW() WHERE user_id = ?')->execute([$uid]);
                $sent[0]++;
            } else {
                $conn->prepare('UPDATE push_nudges SET debt_check_at = NOW() WHERE user_id = ?')->execute([$uid]);
            }
        }

        // 2. people who have not opened the app for a long time
        $rows = !push_cfg_on($conn, 'push_idle') ? [] : $conn->query("SELECT n.user_id, n.idle_stage, DATEDIFF(NOW(), n.last_seen) AS days FROM push_nudges n JOIN users u ON u.id = n.user_id
            WHERE n.user_id > 0 AND $on AND n.idle_stage < " . count($idleDays) . "
            AND (EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.user_id = n.user_id) OR EXISTS (SELECT 1 FROM app_devices a WHERE a.user_id = n.user_id))
            AND n.last_seen < NOW() - INTERVAL " . $idleDays[0] . " DAY
            AND (n.idle_at IS NULL OR n.idle_at < NOW() - INTERVAL 2 DAY) LIMIT " . PUSH_TICK_BATCH)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stage = (int) $r['idle_stage'];
            if ((int) $r['days'] < $idleDays[$stage]) {
                continue;
            }
            $next = $stage + 1;
            if (push_notify_i18n($conn, [(int) $r['user_id']], fn() => push_idle_words($next), 'dashboard.php') > 0) {
                $sent[1]++;
            }
            $conn->prepare('UPDATE push_nudges SET idle_stage = ?, idle_at = NOW() WHERE user_id = ?')->execute([$next, (int) $r['user_id']]);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow push tick failed: ' . $e->getMessage());
    }
    return $sent;
}
