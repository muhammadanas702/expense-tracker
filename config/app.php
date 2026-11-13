<?php

/*
 * Application base settings.
 *
 * $base_url is chosen automatically:
 *   - EXPENSEFLOW_BASE_URL environment variable, if set (use this on your own domain)
 *   - localhost / 127.0.0.1 (any port) -> the folder the app is installed in
 *   - anything else                    -> the production site below
 */

date_default_timezone_set(getenv('EXPENSEFLOW_TIMEZONE') ?: 'Asia/Karachi');

if (!function_exists('app_is_local_host')) {
    /** True when the request is addressed to localhost / 127.0.0.1 / ::1 (with or without a port). */
    function app_is_local_host(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /** URL path of this project relative to the web server's document root ("" when it is the root). */
    function app_local_base_path(): string
    {
        $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
        $doc = str_replace('\\', '/', (string) realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')));
        if ($doc !== '' && strpos($root, $doc) === 0) {
            return rtrim(substr($root, strlen($doc)), '/');
        }
        return '/expense-tracker';
    }
}

$envBaseUrl = getenv('EXPENSEFLOW_BASE_URL');

if ($envBaseUrl) {
    $base_url = rtrim($envBaseUrl, '/');
} elseif (app_is_local_host()) {
    $localScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $base_url = $localScheme . '://' . $_SERVER['HTTP_HOST'] . app_local_base_path();
} else {
    $base_url = 'https://expenseflow.rf.gd';
    // Part 106: when the own domain was made the main address in Admin > Domain, storage/site.json says so.
    $siteJson = @json_decode((string) @file_get_contents(__DIR__ . '/../storage/site.json'), true);
    if (is_array($siteJson) && !empty($siteJson['live']) && is_string($siteJson['host'] ?? null)
        && preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $siteJson['host'])) {
        $base_url = 'https://' . strtolower($siteJson['host']);
    }
}

/*
 * One address for the whole site (Part 61): the plain-http address and any other host name (www, a second domain) are sent
 * to $base_url with a permanent redirect. Google Safe Browsing had flagged http://expenseflow.rf.gd/ (the unencrypted copy of a
 * page with a sign-in form), so nothing is served over plain http any more.
 *  - Only GET/HEAD from a browser on a real (non-local) host; never POST, CLI, health checks or tests on localhost.
 *  - Loop-proof: a redirect to https adds ef_https=1 (and then a Secure cookie ef_s); a request that already has either is served as it
 *    is, even if this host gives no sign that the connection is https.
 *  - Switch off with EXPENSEFLOW_NO_REDIRECT=1.
 */
if (!function_exists('app_redirect_target')) {
    /** The address a request should be sent to, or null when it can be served as it is. Pure, so it can be tested. */
    function app_redirect_target(string $baseUrl, string $host, string $uri, bool $isHttps, bool $marked): ?string
    {
        $b = parse_url($baseUrl);
        $wantHost = strtolower((string) ($b['host'] ?? ''));
        $wantHttps = ($b['scheme'] ?? '') === 'https';
        $host = preg_replace('/:\d+$/', '', strtolower($host));
        if ($wantHost === '' || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return null;
        }
        $uri = preg_replace('/[\r\n]/', '', $uri);
        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/';
        }
        // The free address keeps its admin pages and the health check, so the owner can always get in and switch back.
        if ($host === 'expenseflow.rf.gd' && $host !== $wantHost && preg_match('#^/(admin/|health\.php)#', $uri)) {
            return null;
        }
        if ($host !== $wantHost) {
            return rtrim($baseUrl, '/') . $uri;
        }
        if ($wantHttps && !$isHttps && !$marked) {
            return rtrim($baseUrl, '/') . $uri . (strpos($uri, '?') === false ? '?' : '&') . 'ef_https=1';
        }
        return null;
    }

    function app_enforce_site(string $baseUrl): void
    {
        if (PHP_SAPI === 'cli' || getenv('EXPENSEFLOW_NO_REDIRECT') || headers_sent() || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
        // A browser that came back through our https redirect keeps a small Secure cookie: it is not redirected again on later pages,
        // even if this host never tells PHP that the connection is https. A plain-http visit does not send it, so it is redirected.
        if (isset($_GET['ef_https']) && !isset($_COOKIE['ef_s']) && !headers_sent()) {
            header('Set-Cookie: ef_s=1; Max-Age=31536000; Path=/; Secure; HttpOnly; SameSite=Lax', false);
        }
        $to = app_redirect_target($baseUrl, (string) ($_SERVER['HTTP_HOST'] ?? ''), (string) ($_SERVER['REQUEST_URI'] ?? '/'), $https, isset($_GET['ef_https']) || isset($_COOKIE['ef_s']));
        if ($to !== null) {
            header('Location: ' . $to, true, 301);
            header('Cache-Control: no-store');
            exit();
        }
    }
}
app_enforce_site($base_url);
