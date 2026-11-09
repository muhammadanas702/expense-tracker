<?php
/**
 * Website health (Admin > System > Website health).
 *  - health_checks()  live checks: database, disk, PHP, folders, e-mail, errors, monitor pings
 *  - health_record()  one history row (admin visit, manual check, or an outside monitor calling /health.php?db=1)
 *  - health_probe()   times the main public pages with a real request to this site
 * Everything here is read-only for the site's data; the only table written is health_log (kept for 30 days).
 */
require_once __DIR__ . '/../../includes/schema.php';

function health_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS `health_log` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `created_at` DATETIME NOT NULL,
          `kind` VARCHAR(8) NOT NULL DEFAULT 'check',
          `source` VARCHAR(12) NOT NULL DEFAULT 'admin',
          `ok` TINYINT(1) NOT NULL DEFAULT 1,
          `db_ms` DECIMAL(8,1) NULL,
          `disk_pct` DECIMAL(5,1) NULL,
          `detail` TEXT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_health_time` (`created_at`),
          KEY `idx_health_kind` (`kind`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** The worst of a list of states ('ok' < 'warn' < 'bad'). */
function health_worst(array $states): string
{
    return in_array('bad', $states, true) ? 'bad' : (in_array('warn', $states, true) ? 'warn' : 'ok');
}

/** Free disk space in percent, or null when the host does not report it. */
function health_disk_pct(): ?float
{
    $free = @disk_free_space(__DIR__);
    $total = @disk_total_space(__DIR__);
    return ($free !== false && $total !== false && $total > 0) ? round($free / $total * 100, 1) : null;
}

function health_bytes($b): string
{
    $b = (float) $b;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($b < 1024 || $u === 'TB') {
            return ($u === 'B' ? (string) (int) $b : number_format($b, 1)) . ' ' . $u;
        }
        $b /= 1024;
    }
    return '';
}

/** Database round trip in ms (average of 5 trivial queries), or null when the database does not answer. */
function health_db_ms(PDO $conn): ?float
{
    try {
        $t = microtime(true);
        for ($i = 0; $i < 5; $i++) {
            $conn->query('SELECT 1')->fetchColumn();
        }
        return round((microtime(true) - $t) * 1000 / 5, 1);
    } catch (Throwable $e) {
        return null;
    }
}

/** Where PHP writes its errors, when that is a readable file. */
function health_error_log_path(): ?string
{
    $p = (string) ini_get('error_log');
    return ($p !== '' && is_file($p) && is_readable($p)) ? $p : null;
}

/** The last lines of the PHP error log (newest last), with the site's folder name shortened. */
function health_log_tail(int $lines = 15): array
{
    $p = health_error_log_path();
    if ($p === null) {
        return [];
    }
    $size = (int) @filesize($p);
    $fh = @fopen($p, 'rb');
    if (!$fh) {
        return [];
    }
    if ($size > 65536) {
        fseek($fh, -65536, SEEK_END);
    }
    $chunk = (string) stream_get_contents($fh);
    fclose($fh);
    $all = array_values(array_filter(array_map('trim', explode("\n", $chunk)), fn($l) => $l !== ''));
    $root = dirname(__DIR__, 2);
    return array_map(fn($l) => mb_substr(str_replace($root, '…', $l), 0, 300), array_slice($all, -$lines));
}

/**
 * Live checks. Each row is [label, value, state ('ok'|'warn'|'bad'), hint].
 * $dbMs is passed in so one page view measures the database only once.
 */
function health_checks(PDO $conn, ?float $dbMs): array
{
    $rows = [];

    // Database
    if ($dbMs === null) {
        $rows[] = ['Database', 'Not answering', 'bad', 'The site cannot work without its database. Check the host panel and the DB secrets.'];
    } else {
        $rows[] = ['Database', number_format($dbMs, 1) . ' ms per query', $dbMs > 60 ? 'warn' : 'ok', 'A page makes about 25 queries, so this number x 25 is what the database costs per page. Under 20 ms is good.'];
    }

    // PHP
    $need = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'curl'];
    $missing = array_values(array_filter($need, fn($x) => !extension_loaded($x)));
    $phpOld = version_compare(PHP_VERSION, '8.0.0', '<');
    $rows[] = ['PHP', PHP_VERSION . ($missing ? ' - missing: ' . implode(', ', $missing) : ' - all needed extensions loaded'), ($phpOld || in_array('pdo_mysql', $missing, true)) ? 'bad' : ($missing ? 'warn' : 'ok'), 'ExpenseFlow needs PHP 8 and the pdo_mysql, mbstring, openssl, json and curl extensions.'];

    // Memory
    $limit = (string) ini_get('memory_limit');
    $rows[] = ['Memory', 'limit ' . $limit . ', this page used ' . health_bytes(memory_get_peak_usage(true)), 'ok', 'If pages ever stop with "memory exhausted", the host limit is too low.'];

    // Disk
    $pct = health_disk_pct();
    if ($pct === null) {
        $rows[] = ['Disk space', 'The host does not report it', 'ok', 'Free hosts often hide this. Watch the hosting panel instead.'];
    } else {
        $rows[] = ['Disk space', $pct . '% free (' . health_bytes(@disk_free_space(__DIR__)) . ')', $pct < 5 ? 'bad' : ($pct < 15 ? 'warn' : 'ok'), 'Backups, receipts and sessions live on this disk. Under 15% free needs attention.'];
    }

    // Folders the site writes to
    $root = dirname(__DIR__, 2);
    $dirs = ['storage' => true, 'storage/sessions' => false, 'storage/backups' => false];
    $bad = [];
    foreach ($dirs as $d => $must) {
        if (is_dir($root . '/' . $d) && !is_writable($root . '/' . $d)) {
            $bad[] = $d;
        } elseif ($must && !is_dir($root . '/' . $d)) {
            $bad[] = $d . ' (missing)';
        }
    }
    $rows[] = ['Storage folders', $bad ? 'Not writable: ' . implode(', ', $bad) : 'Writable', $bad ? 'bad' : 'ok', 'Sessions, backups and the speed markers are saved here. If not writable, logins and backups can fail.'];

    // Session files
    $sess = $root . '/storage/sessions';
    if (is_dir($sess)) {
        $n = count(glob($sess . '/sess_*') ?: []);
        $rows[] = ['Session files', (string) $n, $n > 5000 ? 'warn' : 'ok', 'Thousands of old session files slow every page down.'];
    }

    // E-mail
    try {
        require_once __DIR__ . '/../../config/mail.php';
        ef_mail_log_schema($conn);
        $m = $conn->query("SELECT SUM(ok=1) a, SUM(ok=0) b FROM mail_log WHERE created_at > (NOW() - INTERVAL 1 DAY)")->fetch(PDO::FETCH_ASSOC) ?: [];
        $sent = (int) ($m['a'] ?? 0);
        $failed = (int) ($m['b'] ?? 0);
        $set = ef_mail_configured();
        $rows[] = ['E-mail', !$set ? 'Not set up (no SMTP account)' : $sent . ' sent, ' . $failed . ' not sent in 24 h', !$set ? 'bad' : ($failed > 0 && $sent === 0 ? 'bad' : ($failed > 0 ? 'warn' : 'ok')), 'Sign-up and password-reset codes need e-mail. Details are in System > Email log.'];
    } catch (Throwable $e) {
        $rows[] = ['E-mail', 'Could not check', 'warn', ''];
    }

    // HTTPS
    $https = function_exists('settings_is_https') ? settings_is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $rows[] = ['Secure connection (HTTPS)', $https ? 'This page was opened over HTTPS' : 'This page was opened without HTTPS', $https ? 'ok' : 'warn', 'Passwords should only travel over HTTPS.'];

    // PHP error log
    $p = health_error_log_path();
    if ($p === null) {
        $rows[] = ['PHP error log', 'Not available on this host', 'ok', 'Errors are written to the host\'s own log. Open it from the hosting panel when something breaks.'];
    } else {
        $tail = health_log_tail(100);
        $fatal = count(array_filter($tail, fn($l) => stripos($l, 'fatal') !== false));
        $rows[] = ['PHP error log', health_bytes(filesize($p)) . ($fatal ? ', ' . $fatal . ' fatal error(s) in the last lines' : ', no fatal errors lately'), $fatal ? 'bad' : 'ok', 'The newest lines are shown below.'];
    }

    // Outside monitor
    try {
        health_ensure_schema($conn);
        $age = $conn->query("SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM health_log WHERE source = 'ping'")->fetchColumn();
        if ($age === null || $age === false) {
            $rows[] = ['Outside monitor', 'No pings yet', 'warn', 'Add a free monitor (for example UptimeRobot) for the address shown below. It records an uptime history here and e-mails you when the site is down.'];
        } else {
            $age = max(0, (int) $age);
            $rows[] = ['Outside monitor', 'Last ping ' . health_ago($age) . ' ago', $age > 1200 ? 'warn' : 'ok', 'A good monitor pings every 5 minutes. No ping for 20 minutes usually means the site was down or the monitor stopped.'];
        }
    } catch (Throwable $e) {
        // history is optional
    }
    return $rows;
}

function health_ago(int $s): string
{
    if ($s < 90) {
        return $s . ' s';
    }
    if ($s < 5400) {
        return round($s / 60) . ' min';
    }
    if ($s < 172800) {
        return round($s / 3600, 1) . ' h';
    }
    return round($s / 86400) . ' days';
}

/**
 * Saves one history row. $source: 'admin' (someone opened the page), 'manual' (button), 'ping' (outside monitor).
 * Throttled per source so a busy monitor or a refreshing admin does not fill the table. Never throws.
 */
function health_record(PDO $conn, string $source, ?float $dbMs, bool $ok, int $minGap = 0, ?array $detail = null, string $kind = 'check'): void
{
    try {
        health_ensure_schema($conn);
        if ($minGap > 0) {
            // Time is compared inside the database, so a different time zone for PHP and MySQL cannot break the throttle.
            $st = $conn->prepare('SELECT COUNT(*) FROM health_log WHERE source = ? AND kind = ? AND created_at > (NOW() - INTERVAL ' . (int) $minGap . ' SECOND)');
            $st->execute([$source, $kind]);
            if ((int) $st->fetchColumn() > 0) {
                return;
            }
        }
        $conn->prepare('INSERT INTO health_log (created_at, kind, source, ok, db_ms, disk_pct, detail) VALUES (NOW(), ?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $source, $ok ? 1 : 0, $dbMs, health_disk_pct(), $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
        if (random_int(1, 40) === 1) {
            $conn->exec('DELETE FROM health_log WHERE created_at < (NOW() - INTERVAL 30 DAY)');
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow health log failed: ' . $e->getMessage());
    }
}

/** History for the last $hours hours (oldest first), only check and ping rows. */
function health_history(PDO $conn, int $hours = 24, int $limit = 400): array
{
    health_ensure_schema($conn);
    $st = $conn->prepare("SELECT created_at, source, ok, db_ms, disk_pct FROM health_log WHERE kind = 'check' AND created_at > (NOW() - INTERVAL " . (int) $hours . " HOUR) ORDER BY id DESC LIMIT " . (int) $limit);
    $st->execute();
    return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
}

/** Uptime summary from outside-monitor pings: counts, failures and the longest silence in the period. */
function health_uptime(array $rows): array
{
    $pings = array_values(array_filter($rows, fn($r) => $r['source'] === 'ping'));
    $gap = 0;
    $gapAt = null;
    for ($i = 1; $i < count($pings); $i++) {
        $g = strtotime($pings[$i]['created_at']) - strtotime($pings[$i - 1]['created_at']);
        if ($g > $gap) {
            $gap = $g;
            $gapAt = $pings[$i - 1]['created_at'];
        }
    }
    $failed = count(array_filter($pings, fn($r) => !$r['ok']));
    return ['pings' => count($pings), 'failed' => $failed, 'longest_gap' => $gap, 'gap_at' => $gapAt];
}

/** A small inline line chart of the database time. Returns '' when there is not enough data. */
function health_spark(array $rows, int $w = 560, int $h = 70): string
{
    $v = array_values(array_filter(array_map(fn($r) => $r['db_ms'] !== null ? (float) $r['db_ms'] : null, $rows), fn($x) => $x !== null));
    if (count($v) < 2) {
        return '';
    }
    $v = array_slice($v, -120);
    $max = max(max($v), 10.0);
    $n = count($v);
    $pts = [];
    foreach ($v as $i => $x) {
        $pts[] = round($i / ($n - 1) * ($w - 8) + 4, 1) . ',' . round($h - 6 - ($x / $max) * ($h - 12), 1);
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Database time over the last checks" style="width:100%;height:' . $h . 'px;display:block"><polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pts) . '"/></svg>';
}

/**
 * Times the main public pages with a real request to this site (what a visitor would see).
 * Returns rows of [path, status, ms, state]. Admin-only; at most 5 requests with a 5 second limit each.
 */
function health_probe(string $baseUrl): array
{
    $paths = ['/', '/auth/login.php', '/auth/register.php', '/guides/', '/health.php?db=1'];
    $baseUrl = rtrim($baseUrl, '/');
    if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$~', $baseUrl)) {
        return [];
    }
    $out = [];
    foreach ($paths as $p) {
        $url = $baseUrl . $p;
        $t = microtime(true);
        $code = 0;
        $err = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'ExpenseFlow-HealthCheck', CURLOPT_SSL_VERIFYPEER => true]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = (string) curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0, 'user_agent' => 'ExpenseFlow-HealthCheck']]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                $err = 'no answer';
            }
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
                    $code = (int) $m[1];
                }
            }
        }
        $ms = (int) round((microtime(true) - $t) * 1000);
        $state = ($code >= 200 && $code < 400) ? ($ms > 4000 ? 'bad' : ($ms > 1500 ? 'warn' : 'ok')) : 'bad';
        $out[] = ['path' => $p, 'status' => $code, 'ms' => $ms, 'state' => $state, 'error' => $err];
    }
    return $out;
}
