<?php
require_once __DIR__ . '/../../includes/schema.php';
/**
 * System settings module. Touches only app_settings (key/value).
 * Every read is best-effort and falls back to the default, so a problem here can never take the site down.
 */
const SETTINGS_MESSAGE_MAX = 200;
const SETTINGS_DEFAULTS = [
    'maintenance' => '0',
    'maintenance_msg' => 'ExpenseFlow is being updated. Please try again in a few minutes.',
    'signups' => '1',
    // "Apps & contact": edited by staff in Admin > Settings, used by the landing page, profile and contact page.
    'link_android' => 'https://github.com/muhammadanas702/expense-tracker/releases/latest/download/ExpenseFlow.apk',
    'link_windows' => 'https://github.com/muhammadanas702/expense-tracker/releases/latest/download/ExpenseFlow-Setup.exe',
    'link_store' => '',
    'contact_email' => 'expenseflowteam@gmail.com',
];
const SETTINGS_LINK_KEYS = ['link_android' => 'Android download link', 'link_windows' => 'Windows download link', 'link_store' => 'Microsoft Store link'];

function settings_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `app_settings` (
              `k` VARCHAR(30) NOT NULL,
              `v` VARCHAR(255) NOT NULL DEFAULT '',
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow settings schema check failed: ' . $e->getMessage());
    }
}

/** All settings (defaults overlaid with what is saved). Cached per request; never throws. */
function settings_all(?PDO $conn = null, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $out = SETTINGS_DEFAULTS;
    try {
        $conn = $conn ?: ($GLOBALS['conn'] ?? null);
        if ($conn instanceof PDO) {
            settings_ensure_schema($conn);
            foreach ($conn->query('SELECT k, v FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if (array_key_exists($k, SETTINGS_DEFAULTS)) {
                    $out[$k] = (string) $v;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow settings read failed: ' . $e->getMessage());
    }
    return $cache = $out;
}

const SETTINGS_PUBLIC_FILE = __DIR__ . '/../../storage/public-settings.json';

/**
 * Value for public pages (landing, contact, profile). Read from a small file written when staff save Settings, so these
 * pages keep working without the database (outage or maintenance). Falls back to the built-in default.
 */
function settings_public(string $key): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            if (is_file(SETTINGS_PUBLIC_FILE)) {
                $j = json_decode((string) file_get_contents(SETTINGS_PUBLIC_FILE), true);
                $cache = is_array($j) ? $j : [];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    $v = $cache[$key] ?? null;
    return is_string($v) && ($v !== '' || $key === 'link_store') ? $v : (SETTINGS_DEFAULTS[$key] ?? '');
}

function settings_get(string $key, ?PDO $conn = null): string
{
    return settings_all($conn)[$key] ?? (SETTINGS_DEFAULTS[$key] ?? '');
}

function settings_signups_open(?PDO $conn = null): bool
{
    return settings_get('signups', $conn) !== '0';
}

/**
 * Validates the staff form. Returns [values|null, error|null]; values holds every key.
 */
function settings_validate(array $in): array
{
    $raw = trim((string) ($in['maintenance_msg'] ?? ''));
    $msg = $raw === '' ? SETTINGS_DEFAULTS['maintenance_msg'] : clean_text($raw, SETTINGS_MESSAGE_MAX);
    if ($msg === null) {
        return [null, 'The maintenance message can be up to ' . SETTINGS_MESSAGE_MAX . ' characters.'];
    }
    $vals = ['maintenance' => !empty($in['maintenance']) ? '1' : '0', 'maintenance_msg' => $msg, 'signups' => !empty($in['signups']) ? '1' : '0'];
    foreach (SETTINGS_LINK_KEYS as $k => $label) {
        $u = trim((string) ($in[$k] ?? ''));
        if ($u === '') {
            $vals[$k] = $k === 'link_store' ? '' : SETTINGS_DEFAULTS[$k]; // only the Store link may be empty (it is then hidden)
        } elseif (strlen($u) > 255 || !preg_match('#^https://[^\s<>"\']+$#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
            return [null, $label . ' must be a full https:// address (up to 255 characters).'];
        } else {
            $vals[$k] = $u;
        }
    }
    $mail = strtolower(trim((string) ($in['contact_email'] ?? '')));
    if ($mail === '') {
        $mail = SETTINGS_DEFAULTS['contact_email'];
    }
    if (strlen($mail) > 120 || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
        return [null, 'Please enter a valid contact e-mail address.'];
    }
    $vals['contact_email'] = $mail;
    return [$vals, null];
}

/** Saves values; returns the human-readable list of what changed. */
function settings_save(PDO $conn, array $values): array
{
    settings_ensure_schema($conn);
    $now = settings_all($conn, true);
    $changes = [];
    $up = $conn->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    foreach ($values as $k => $v) {
        if (!array_key_exists($k, SETTINGS_DEFAULTS) || $now[$k] === $v) {
            continue;
        }
        $up->execute([$k, $v]);
        $changes[] = match ($k) {
            'maintenance' => 'maintenance ' . ($v === '1' ? 'on' : 'off'),
            'signups' => 'sign-ups ' . ($v === '1' ? 'open' : 'closed'),
            'link_android', 'link_windows', 'link_store' => (SETTINGS_LINK_KEYS[$k] ?? $k) . ' changed',
            'contact_email' => 'contact e-mail changed',
            default => 'maintenance message changed',
        };
    }
    $all = settings_all($conn, true);
    try { // copy of the public values for pages that must not need the database
        $pub = array_intersect_key($all, array_flip(['link_android', 'link_windows', 'link_store', 'contact_email']));
        @file_put_contents(SETTINGS_PUBLIC_FILE, json_encode($pub), LOCK_EX);
    } catch (Throwable $e) {
        error_log('ExpenseFlow public settings file: ' . $e->getMessage());
    }
    return $changes;
}

/**
 * Maintenance mode: while it is on, only signed-in staff (and the login/logout pages) work.
 * Called from config/db.php. Fails open and never runs on the command line.
 */
function settings_enforce_maintenance(PDO $conn): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    try {
        if (settings_get('maintenance', $conn) !== '1') {
            return;
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (preg_match('#/auth/(login|logout|forgot-password|send-otp|verify-otp|set-new-password)\.php$#', $script)) {
            return;
        }
        if (!empty($_SESSION['user_id'])) {
            $r = $conn->prepare('SELECT role, is_admin FROM users WHERE id = ?');
            $r->execute([(int) $_SESSION['user_id']]);
            $u = $r->fetch(PDO::FETCH_ASSOC);
            if ($u && (in_array($u['role'] ?? '', ['super', 'admin', 'support'], true) || (int) $u['is_admin'] === 1)) {
                return;
            }
        }
        $msg = settings_get('maintenance_msg', $conn);
        http_response_code(503);
        header('Retry-After: 600');
        header('Cache-Control: no-store');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><link rel="icon" href="/favicon.ico"><title>Be right back · ExpenseFlow</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:linear-gradient(135deg,#d9f7ef,#e6eefc 55%,#fde6cf);color:#0b2545;padding:20px}'
            . 'main{max-width:420px;background:rgba(255,255,255,.75);border-radius:22px;padding:30px 26px;box-shadow:0 20px 50px rgba(11,37,69,.12);text-align:center}h1{margin:0 0 10px;font-size:1.4rem}p{margin:0 0 18px;color:#4a5b73;line-height:1.5}a{color:#1d4ed8;font-weight:600}</style></head>'
            . '<body><main><h1>We will be right back</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><a href="auth/login.php">Staff sign-in</a></main></body></html>';
        exit();
    } catch (Throwable $e) {
        error_log('ExpenseFlow maintenance check failed: ' . $e->getMessage());
    }
}
