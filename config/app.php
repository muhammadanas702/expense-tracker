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
}
