<?php
/**
 * "Needs attention" list for the admin home page: the things a staff member should look at today, in plain words, each with a button to the
 * page that fixes it. Only shows what this staff member is allowed to open. Every check is wrapped, so one broken check never breaks the page.
 */
require_once __DIR__ . '/../features/lib.php';
require_once __DIR__ . '/../health/lib.php';

/** @return array<int,array{level:string,title:string,detail:string,url:string,go:string}> worst first */
function admin_attention(PDO $conn, array $admin): array
{
    $role = (string) $admin['role'];
    $can = fn(string $p) => admin_can($conn, $role, $p);
    $items = [];
    $add = function (string $level, string $title, string $detail, string $url, string $go) use (&$items) {
        $items[] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'url' => $url, 'go' => $go];
    };
    $count = function (string $sql) use ($conn): int {
        try {
            return (int) $conn->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };

    if ($can('set')) {
        try {
            if (function_exists('settings_get') && settings_get('maintenance', $conn) === '1') {
                $add('bad', 'The website is paused (maintenance mode is on)', 'Visitors see the maintenance message and cannot use the app. Only staff can sign in.', 'admin/settings.php', 'Open Settings');
            }
        } catch (Throwable $e) {
        }
        try {
            $ms = health_db_ms($conn);
            $rows = health_checks($conn, $ms);
            $bad = array_values(array_filter($rows, fn($r) => $r[2] === 'bad'));
            $warn = array_values(array_filter($rows, fn($r) => $r[2] === 'warn'));
            if ($bad) {
                $add('bad', count($bad) . ' website health check' . (count($bad) > 1 ? 's' : '') . ' failing', 'Failing: ' . implode(', ', array_map(fn($r) => $r[0], array_slice($bad, 0, 3))) . '. The page explains each one in plain words.', 'admin/health.php', 'Open Website health');
            } elseif ($warn) {
                $add('warn', count($warn) . ' website health warning' . (count($warn) > 1 ? 's' : ''), 'Worth a look: ' . implode(', ', array_map(fn($r) => $r[0], array_slice($warn, 0, 3))) . '. Nothing is broken yet.', 'admin/health.php', 'Open Website health');
            }
        } catch (Throwable $e) {
            $add('warn', 'The health check itself could not run', 'The database may be slow or unreachable. Open the page to see more.', 'admin/health.php', 'Open Website health');
        }
        $fails = $count("SELECT COUNT(*) FROM mail_log WHERE ok = 0 AND created_at > NOW() - INTERVAL 1 DAY");
        if ($fails > 0) {
            $add($fails >= 3 ? 'bad' : 'warn', $fails . ' e-mail' . ($fails > 1 ? 's' : '') . ' could not be sent in the last 24 hours', 'Login codes and password-reset e-mails may not be reaching people. The log shows the reason for each.', 'admin/mail.php', 'Open Email log');
        }
        try {
            $off = features_off_list(true);
            if ($off) {
                $flat = features_flat();
                $add('info', count($off) . ' option' . (count($off) > 1 ? 's are' : ' is') . ' switched off', implode(', ', array_map(fn($id) => $flat[$id]['label'] ?? $id, array_slice($off, 0, 4))) . (count($off) > 4 ? ' and more' : '') . '. Users cannot use ' . (count($off) > 1 ? 'them' : 'it') . ' until you switch back on.', 'admin/features.php', 'Open Features on/off');
            }
        } catch (Throwable $e) {
        }
    }
    if ($can('support')) {
        $open = $count("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'");
        if ($open > 0) {
            $add('warn', $open . ' user' . ($open > 1 ? 's are' : ' is') . ' waiting for an answer', 'Open help requests that nobody has replied to yet.', 'admin/support.php?s=open', 'Open Support');
        }
    }
    if ($can('backup')) {
        try {
            require_once __DIR__ . '/../backup/lib.php';
            if (backup_is_stale()) {
                $age = backup_age_days();
                $add('warn', $age === null ? 'There is no backup yet' : 'The newest backup is ' . $age . ' days old', 'A backup is a safe copy of all the data. Make a fresh one so nothing is lost if something goes wrong.', 'admin/backups.php', 'Open Backups');
            }
        } catch (Throwable $e) {
        }
    }
    if ($can('log')) {
        try {
            require_once __DIR__ . '/../security/lib.php';
            $st = security_stats($conn);
            if ($st['locked24'] > 0 || $st['fail24'] >= 20) {
                $add('info', $st['fail24'] . ' failed sign-ins in the last 24 hours' . ($st['locked24'] ? ', ' . $st['locked24'] . ' account lock' . ($st['locked24'] > 1 ? 's' : '') : ''), 'Usually people forgetting passwords. A very high number can mean someone is guessing passwords.', 'admin/security.php', 'Open Security');
            }
        } catch (Throwable $e) {
        }
    }
    $rank = ['bad' => 0, 'warn' => 1, 'info' => 2];
    usort($items, fn($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $items;
}
