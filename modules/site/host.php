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
    $ch = curl_init(site_check_base($host) . '/health.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'ExpenseFlow-site-check']);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($raw === false || $code === 0) {
        $out[] = ['https', false, 'https://' . $host . ' did not answer (' . ($err !== '' ? $err : 'no connection') . '). The secure certificate (SSL) may not be ready yet.'];
        return $out;
    }
    $out[] = ['https', $code < 500, 'https://' . $host . ' answered with a valid certificate (HTTP ' . $code . ').'];
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
