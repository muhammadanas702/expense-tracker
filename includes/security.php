<?php
/**
 * Shared security helpers for ExpenseFlow.
 *
 * Include this file FIRST in every page (before config/db.php), then call
 * secure_session_start() instead of session_start().
 */

if (defined('APP_SECURITY_LOADED')) {
    return;
}
define('APP_SECURITY_LOADED', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/currencies.php';

/** Largest amount accepted for a single income/expense entry. */
const APP_MAX_AMOUNT = 99999999.99;
/** Idle time (seconds) after which a session is dropped. */
const APP_SESSION_IDLE = 14400; // 4 hours

/* ------------------------------------------------------------------ */
/* Output and request helpers                                          */
/* ------------------------------------------------------------------ */

/** HTML-escape a value for output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The visitor's IP. Only REMOTE_ADDR is trusted; forwarded headers are client-controlled. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return !empty($_SERVER['HTTP_CF_VISITOR']) && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
    if (app_is_https()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/* ------------------------------------------------------------------ */
/* Sessions                                                            */
/* ------------------------------------------------------------------ */

/** Starts the session with hardened cookie settings and an idle timeout. */
function secure_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    send_security_headers();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) APP_SESSION_IDLE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > APP_SESSION_IDLE) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = $now;
}

/** Call right after a successful login to prevent session fixation. */
function session_after_login(int $userId, string $userName): void
{
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $userName;
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent)
        && $sent !== ''
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/** Stops the request unless it is a POST carrying a valid CSRF token. */
function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('This action must be submitted from the app (POST). Please go back and use the button.');
    }
    if (!csrf_valid()) {
        http_response_code(403);
        exit('Security check failed. Please go back, refresh the page and try again.');
    }
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/** Returns an error message, or null when the password meets the policy. */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters long.';
    }
    if (
        !preg_match('/[a-z]/', $password)
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        return 'Password must contain an uppercase letter, a lowercase letter, a number and a special character.';
    }
    return null;
}

/** Returns $value if it is a real Y-m-d date, otherwise null. */
function valid_date($value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Parses a money amount from user input. Returns null if it is not a usable positive amount. */
function parse_amount($value): ?float
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if (!is_numeric($value)) {
        return null;
    }
    $amount = round((float) $value, 2);
    if (!is_finite($amount) || $amount <= 0 || $amount > APP_MAX_AMOUNT) {
        return null;
    }
    return $amount;
}

/** Trims text and enforces a maximum length (in characters). Returns null if empty or too long. */
function clean_text($value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
    if ($text === '' || mb_strlen($text, 'UTF-8') > $maxLength) {
        return null;
    }
    return $text;
}

/** Month (1-12) from ?month=, defaulting to the current month. */
function request_month(): int
{
    $m = filter_var($_GET['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    return $m === false || $m === null ? (int) date('n') : (int) $m;
}

/** Year from ?year=, limited to a sensible range. */
function request_year(): int
{
    $y = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    return $y === false || $y === null ? (int) date('Y') : (int) $y;
}

/* ------------------------------------------------------------------ */
/* Rate limiting (stored in the database, so it works across sessions) */
/* ------------------------------------------------------------------ */

function rl_key(string ...$parts): string
{
    return hash('sha256', strtolower(implode('|', $parts)));
}

/** True when $max or more hits were recorded for this bucket/key inside the window. */
function rl_blocked(PDO $conn, string $bucket, string $key, int $max, int $windowSeconds): bool
{
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND key_hash = ? AND created_at > (NOW() - INTERVAL ' . (int) $windowSeconds . ' SECOND)'
        );
        $stmt->execute([$bucket, $key]);
        return (int) $stmt->fetchColumn() >= $max;
    } catch (Throwable $e) {
        error_log('ExpenseFlow rate limit check failed: ' . $e->getMessage());
        return false; // fail open: never lock real users out because of a bookkeeping problem
    }
}

function rl_hit(PDO $conn, string $bucket, string $key): void
{
    try {
        $conn->prepare('INSERT INTO rate_limit_hits (bucket, key_hash, created_at) VALUES (?, ?, NOW())')
            ->execute([$bucket, $key]);
        if (random_int(1, 50) === 1) {
            $conn->exec('DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow rate limit record failed: ' . $e->getMessage());
    }
}

function rl_clear(PDO $conn, string $bucket, string $key): void
{
    try {
        $conn->prepare('DELETE FROM rate_limit_hits WHERE bucket = ? AND key_hash = ?')->execute([$bucket, $key]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow rate limit clear failed: ' . $e->getMessage());
    }
}

/** A message may have line breaks (unlike clean_text); control characters are removed. */
function clean_multiline($value, int $max): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $t = preg_replace('/[^\P{C}\n]/u', '', str_replace(["\r\n", "\r"], "\n", $value));
    $t = trim(preg_replace("/\n{3,}/", "\n\n", (string) $t));
    if ($t === '' || mb_strlen($t, 'UTF-8') > $max) {
        return null;
    }
    return $t;
}
