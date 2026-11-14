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
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/../modules/features/lib.php';
ef_feature_gate(); // a switched-off feature shows a friendly page instead of its own
ef_install_error_handlers(); // friendly page instead of a blank screen when something unexpected breaks

/** Largest amount accepted for a single income/expense entry. */
const APP_MAX_AMOUNT = 99999999.99;
/** Idle time (seconds) after which a session is dropped. */
const APP_SESSION_IDLE = 259200;   // signed out after 3 days without use (every visit starts the 3 days again)
const APP_SESSION_KEEP = 2592000;  // the browser/app keeps the login cookie and the server keeps the session file for 30 days, so an expired login can be told apart from a new visitor

/* ------------------------------------------------------------------ */
/* Output and request helpers                                          */
/* ------------------------------------------------------------------ */

/** HTML-escape a value for output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Cloudflare's published address ranges (https://www.cloudflare.com/ips). Only these may tell us the visitor's real IP. */
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** Is $ip inside $cidr (IPv4 or IPv6)? */
function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr) + [1 => '0'];
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = (int) $bits;
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($a, 0, $full) !== substr($b, 0, $full)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
}

/**
 * The visitor's IP. REMOTE_ADDR is trusted; forwarded headers are client-controlled, so CF-Connecting-IP counts
 * only when the request really comes from a Cloudflare address (the own domain runs behind Cloudflare).
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'unknown';
    }
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        foreach (CLOUDFLARE_RANGES as $range) {
            if (ip_in_cidr($ip, $range)) {
                return $cf;
            }
        }
    }
    return $ip;
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
    ini_set('session.gc_maxlifetime', (string) APP_SESSION_KEEP);
    // Own session folder: on shared hosting other sites' clean-up would otherwise delete our session files after minutes.
    $dir = __DIR__ . '/../storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        ini_set('session.save_path', $dir);
    }
    session_set_cookie_params([
        'lifetime' => APP_SESSION_KEEP,
        'path'     => '/',
        'secure'   => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > APP_SESSION_IDLE) {
        $wasSignedIn = !empty($_SESSION['user_id']);
        session_unset();
        session_destroy();
        session_start();
        if ($wasSignedIn) {
            $_SESSION['reset_message'] = 'You were signed out because the account was not used for 3 days. Please log in again.';
            $_SESSION['reset_type'] = 'error';
        }
    }
    $_SESSION['last_activity'] = $now;
    // Sliding window: every visit pushes the cookie's end date forward again.
    if (!headers_sent()) {
        setcookie(session_name(), session_id(), [
            'expires'  => $now + APP_SESSION_KEEP,
            'path'     => '/',
            'secure'   => app_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
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
