<?php
/**
 * Site address (Part 106). The site normally lives at the free address (expenseflow.rf.gd). When your own domain (for example
 * expenseflow.page) works, Admin > Domain makes it the main address: every other address is then sent to it (config/app.php reads
 * storage/site.json). The switch is only allowed after a live check passes, and "Go back" is always one click away, and the old
 * address keeps its /admin/ pages and /health.php, so a mistake can never lock the owner out.
 */
const SITE_LEGACY_HOST = 'expenseflow.rf.gd';

function site_file(): string
{
    return dirname(__DIR__, 2) . '/storage/site.json';
}

function site_valid_host($h): bool
{
    return is_string($h) && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $h) === 1;
}

function site_config(): array
{
    $c = @json_decode((string) @file_get_contents(site_file()), true);
    $c = is_array($c) ? $c : [];
    return ['host' => is_string($c['host'] ?? null) ? strtolower($c['host']) : '', 'live' => !empty($c['live']), 'since' => (int) ($c['since'] ?? 0)];
}

function site_save(array $c): bool
{
    $f = site_file();
    $ok = @file_put_contents($f, json_encode($c, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    @chmod($f, 0640);
    return $ok;
}

/** The base URL of the host the site is moved to, or null while the site stays at the free address. */
function site_live_base(): ?string
{
    $c = site_config();
    return $c['live'] && site_valid_host($c['host']) ? 'https://' . $c['host'] : null;
}

/** Where the live check looks. Tests point it at a local server with EXPENSEFLOW_SITE_CHECK_BASE. */
function site_check_base(string $host): string
{
    return rtrim(getenv('EXPENSEFLOW_SITE_CHECK_BASE') ?: 'https://' . $host, '/');
}

/**
 * One GET to a /health.php address: [body|false, http code, curl error, certificate-list warning].
 * Free hosts ship an old list of trusted certificate authorities, so a perfectly good Let's Encrypt certificate can fail
 * there with "unable to get local issuer certificate" while every browser accepts it. For this only-looking check we then
 * retry once without verifying and say so; nothing secret is sent and nothing is changed by the answer.
 */
function site_probe(string $url, int $connect, int $total): array
{
    $run = function (bool $verify) use ($url, $connect, $total): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $connect, CURLOPT_TIMEOUT => $total, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'ExpenseFlow-site-check', CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0]);
        $raw = curl_exec($ch);
        $r = [$raw, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $raw === false ? curl_error($ch) : '', curl_errno($ch)];
        curl_close($ch);
        return $r;
    };
    [$raw, $code, $err, $no] = $run(true);
    if ($raw === false && in_array($no, [58, 59, 60, 77, 83], true) && stripos($url, 'https://') === 0) {
        [$raw2, $code2] = $run(false);
        if ($raw2 !== false && $code2 > 0) {
            return [$raw2, $code2, '', true];
        }
    }
    return [$raw, $code, $err, false];
}

/** Three plain checks for a host: [['dns'|'https'|'app', ok, text], ...]. Nothing is changed. */
function site_check(string $host): array
{
    $host = strtolower(trim($host));
    if (!site_valid_host($host)) {
        return [['dns', false, 'Type the address as a plain host name, for example expenseflow.page (no https://, no slash).']];
    }
    $out = [];
    $ip = getenv('EXPENSEFLOW_SITE_CHECK_BASE') ? '127.0.0.1' : @gethostbyname($host);
    $dnsOk = $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    $out[] = ['dns', $dnsOk, $dnsOk ? $host . ' points to ' . $ip . '.' : $host . ' does not point anywhere yet. Add the DNS record (see the steps below) and wait a few minutes.'];
    if (!$dnsOk) {
        return $out;
    }
    if (!function_exists('curl_init')) {
        $out[] = ['https', false, 'The curl extension is not available on this server, so the live check cannot run. Open https://' . $host . '/health.php in a browser instead.'];
        return $out;
    }
    [$raw, $code, $err, $oldCa] = site_probe(site_check_base($host) . '/health.php', 8, 15);
    if ($raw === false || $code === 0) {
        $out[] = ['https', false, 'https://' . $host . ' did not answer (' . ($err !== '' ? $err : 'no connection') . '). The secure certificate (SSL) may not be ready yet.'];
        return $out;
    }
    $out[] = ['https', $code < 500, 'https://' . $host . ' answered (HTTP ' . $code . ').' . ($oldCa ? ' This server\'s own list of trusted certificate authorities is old, so it could not verify the certificate itself; browsers can. Open https://' . $host . ' once and look for the lock.' : ' The certificate is valid.')];
    $j = json_decode((string) $raw, true);
    $appOk = $code === 200 && is_array($j) && !empty($j['ok']);
    $botPage = !$appOk && stripos((string) $raw, 'aes.js') !== false; // the free host shows scripts a "visitor check" page: the domain does reach it
    $out[] = ['app', $appOk || $botPage, $appOk ? 'ExpenseFlow answers on ' . $host . '.' : ($botPage ? 'The domain reaches the host (its visitor-check page answered, which is normal for scripts).' : 'The address opens, but it is not ExpenseFlow (HTTP ' . $code . '). It may still show a parking page, or the host does not have this domain added yet.')];
    return $out;
}

function site_check_passed(array $steps): bool
{
    return count($steps) === 3 && !in_array(false, array_column($steps, 1), true);
}

/**
 * Is the main address answering? Used only on the free address: when the own domain is the main address but is down
 * (Cloudflare or DNS trouble) the free address serves the site itself instead of sending people to a dead page.
 * The answer is remembered for a short time (storage/site-health.json), so a visit costs at most one 3-second check per 2 minutes.
 */
function site_main_healthy(): bool
{
    $base = site_live_base();
    if ($base === null) {
        return true;
    }
    $f = dirname(__DIR__, 2) . '/storage/site-health.json';
    $c = @json_decode((string) @file_get_contents($f), true);
    if (is_array($c) && ($c['base'] ?? '') === $base && time() - (int) ($c['t'] ?? 0) < (!empty($c['ok']) ? 120 : 30)) {
        return !empty($c['ok']);
    }
    $ok = false;
    if (function_exists('curl_init')) {
        [$raw, $code] = site_probe((getenv('EXPENSEFLOW_SITE_CHECK_BASE') ?: $base) . '/health.php', 2, 3);
        $ok = $raw !== false && $code > 0 && $code < 500;
    } else {
        $ok = true; // cannot check: keep the normal redirect
    }
    @file_put_contents($f, json_encode(['base' => $base, 't' => time(), 'ok' => $ok]), LOCK_EX);
    return $ok;
}
