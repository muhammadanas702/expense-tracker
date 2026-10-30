<?php
/**
 * Google sign-in settings. The button only appears when a client id and secret are configured.
 *
 * Where they come from (first match wins):
 *   1. config/google.local.php   return ['client_id' => '...apps.googleusercontent.com', 'client_secret' => '...'];   (private, never committed)
 *   2. environment variables EXPENSEFLOW_GOOGLE_CLIENT_ID and EXPENSEFLOW_GOOGLE_CLIENT_SECRET
 * The deploy workflow writes config/google.local.php from the GitHub secrets of the same names.
 */
function google_settings(): ?array
{
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    $cfg = null;
    $file = __DIR__ . '/google.local.php';
    $c = is_file($file) ? include $file : null;
    $id = is_array($c) ? (string) ($c['client_id'] ?? '') : (string) (getenv('EXPENSEFLOW_GOOGLE_CLIENT_ID') ?: '');
    $secret = is_array($c) ? (string) ($c['client_secret'] ?? '') : (string) (getenv('EXPENSEFLOW_GOOGLE_CLIENT_SECRET') ?: '');
    if ($id !== '' && $secret !== '') {
        $cfg = ['client_id' => $id, 'client_secret' => $secret];
    }
    return $cfg;
}
