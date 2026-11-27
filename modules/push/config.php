<?php
/**
 * Admin controls for phone alerts (Admin > Notifications). Saved in app_settings; every read falls back to the default,
 * so a problem here can never stop the site. Times are Pakistan time (UTC+5) in the form and UTC in the code.
 */
const PUSH_CFG_DEFAULTS = [
    'push_web' => '1',        // alerts to browsers (Web Push)
    'push_app' => '1',        // alerts to the Android app
    'push_events' => '1',     // shared bills, payments, comments, group changes
    'push_reminders' => '1',  // bill reminders and budget warnings
    'push_debt' => '1',       // "you still owe" reminder
    'push_debt_days' => '2',
    'push_idle' => '1',       // "we miss you" nudges
    'push_idle_days' => '3,7,14,30',
    'push_from' => '9',       // nudges are sent between these hours, Pakistan time
    'push_to' => '22',
    'push_last_all' => '0',   // when the last announcement to everyone was sent (10-minute guard)
];
const PUSH_PK_OFFSET = 5;

function push_cfg(PDO $conn, string $key): string
{
    $cache = &$GLOBALS['__push_cfg'];
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($conn->query("SELECT k, v FROM app_settings WHERE k LIKE 'push\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $cache[$k] = (string) $v;
            }
        } catch (Throwable $e) {
            // table not there yet: defaults
        }
    }
    return $cache[$key] ?? (PUSH_CFG_DEFAULTS[$key] ?? '');
}

function push_cfg_on(PDO $conn, string $key): bool
{
    return push_cfg($conn, $key) !== '0';
}

/** The "we miss you" days as a sorted list of 1-4 whole numbers. */
function push_idle_days(PDO $conn): array
{
    $d = array_values(array_unique(array_filter(array_map('intval', explode(',', push_cfg($conn, 'push_idle_days'))), fn($n) => $n >= 1 && $n <= 365)));
    sort($d);
    return $d ? array_slice($d, 0, 4) : [3, 7, 14, 30];
}

/** Is it a good time (Pakistan time, inside the chosen hours) to send a nudge? */
function push_in_window(PDO $conn): bool
{
    $h = ((int) gmdate('G') + PUSH_PK_OFFSET) % 24;
    $from = max(0, min(23, (int) push_cfg($conn, 'push_from')));
    $to = max(1, min(24, (int) push_cfg($conn, 'push_to')));
    return $from < $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);
}

/** Validates the admin form. Returns [values|null, error|null]. */
function push_cfg_validate(array $in): array
{
    $v = [];
    foreach (['push_web', 'push_app', 'push_events', 'push_reminders', 'push_debt', 'push_idle'] as $k) {
        $v[$k] = !empty($in[$k]) ? '1' : '0';
    }
    $days = (int) ($in['push_debt_days'] ?? 0);
    if ($days < 1 || $days > 30) {
        return [null, 'Debt reminder: choose between 1 and 30 days.'];
    }
    $v['push_debt_days'] = (string) $days;
    $list = [];
    foreach (explode(',', (string) ($in['push_idle_days'] ?? '')) as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (!ctype_digit($p) || (int) $p < 1 || (int) $p > 365) {
            return [null, '"We miss you" days: write whole numbers between 1 and 365, like 3, 7, 14, 30.'];
        }
        $list[(int) $p] = true;
    }
    $list = array_keys($list);
    sort($list);
    if (count($list) < 1 || count($list) > 4) {
        return [null, '"We miss you": give 1 to 4 numbers of days.'];
    }
    $v['push_idle_days'] = implode(',', $list);
    $from = (int) ($in['push_from'] ?? -1);
    $to = (int) ($in['push_to'] ?? -1);
    if ($from < 0 || $from > 23 || $to < 1 || $to > 24 || $from === $to) {
        return [null, 'Sending hours: choose a start (0-23) and an end (1-24) that are different.'];
    }
    $v['push_from'] = (string) $from;
    $v['push_to'] = (string) $to;
    return [$v, null];
}

/** Saves the values; returns plain words for the audit log. */
function push_cfg_save(PDO $conn, array $vals): array
{
    require_once __DIR__ . '/../settings/lib.php';
    settings_ensure_schema($conn);
    $changes = [];
    $up = $conn->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    foreach ($vals as $k => $v) {
        if (!array_key_exists($k, PUSH_CFG_DEFAULTS)) {
            continue;
        }
        $old = (string) $conn->query("SELECT v FROM app_settings WHERE k = " . $conn->quote($k))->fetchColumn();
        $old = $old === '' ? PUSH_CFG_DEFAULTS[$k] : $old;
        if ($old === $v) {
            continue;
        }
        $up->execute([$k, $v]);
        $GLOBALS['__push_cfg'] = null;
        $changes[] = substr($k, 5) . ' ' . $old . ' -> ' . $v;
    }
    return $changes;
}
