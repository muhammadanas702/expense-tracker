<?php
require_once __DIR__ . '/../features/lib.php';
require_once __DIR__ . '/../../includes/schema.php';
/**
 * Two-step sign-in (table `user_2fa`). The second step is a 6-digit code sent to the account e-mail.
 * - Users can turn it on in Settings > Security (the code is checked first, so a wrong address cannot lock them out).
 * - Admin > Settings decides who is asked besides people who turned it on themselves: nobody (default), staff only
 *   (support, admin, super) or everyone. Registration always uses an e-mailed code to confirm the address (not this module).
 * - It is skipped (never blocks anybody) when the server cannot send e-mail at all, or when EXPENSEFLOW_2FA_OFF=1 is set
 *   (the emergency switch for the host owner). Google sign-in is not asked again: Google already verified the person.
 * Codes live in the session only (hashed), valid 10 minutes, 5 tries, at most 10 mails per hour per account.
 */
const TWOFA_TTL = 600;
const TWOFA_MAX_TRIES = 5;
const TWOFA_MAX_MAILS = 10;

function twofa_ensure_schema(PDO $conn): void
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
            "CREATE TABLE IF NOT EXISTS `user_2fa` (
              `user_id`    INT UNSIGNED NOT NULL,
              `enabled_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow twofactor schema: ' . $e->getMessage());
    }
}

function twofa_staff_role(string $role): bool
{
    return in_array($role, ['support', 'admin', 'super'], true);
}

/** Can codes be e-mailed right now? When not, two-step sign-in is skipped instead of locking people out. */
function twofa_available(): bool
{
    if (getenv('EXPENSEFLOW_2FA_OFF') || !ef_feature_on('twofactor')) {
        return false;
    }
    require_once __DIR__ . '/../../config/mail.php';
    return ef_mail_configured();
}

function twofa_role(PDO $conn, int $uid): string
{
    try {
        $st = $conn->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([$uid]);
        return (string) $st->fetchColumn();
    } catch (Throwable $e) {
        return 'user';
    }
}

function twofa_enabled(PDO $conn, int $uid): bool
{
    try {
        twofa_ensure_schema($conn);
        $st = $conn->prepare('SELECT 1 FROM user_2fa WHERE user_id = ?');
        $st->execute([$uid]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Does this account have to enter an e-mailed code at sign-in? */
function twofa_required(PDO $conn, int $uid): bool
{
    if (!twofa_available()) {
        return false;
    }
    return twofa_mandatory($conn, $uid) || twofa_enabled($conn, $uid);
}

/** Admin setting "E-mailed code when signing in": is this account asked whether or not it opted in? */
function twofa_mandatory(PDO $conn, int $uid): bool
{
    require_once __DIR__ . '/../settings/lib.php';
    $mode = settings_all($conn)['login_code'] ?? 'off';
    return $mode === 'all' || ($mode === 'staff' && twofa_staff_role(twofa_role($conn, $uid)));
}

function twofa_set(PDO $conn, int $uid, bool $on): void
{
    twofa_ensure_schema($conn);
    if ($on) {
        $conn->prepare('INSERT IGNORE INTO user_2fa (user_id) VALUES (?)')->execute([$uid]);
    } else {
        $conn->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$uid]);
    }
}

/** Makes a code for $purpose ('login', 'on', 'off'), keeps its hash in the session and e-mails it. False when it could not be sent. */
function twofa_send_code(PDO $conn, int $uid, string $purpose): bool
{
    require_once __DIR__ . '/../../config/mail.php';
    $key = rl_key((string) $uid);
    if (rl_blocked($conn, 'twofa_send', $key, TWOFA_MAX_MAILS, 3600)) {
        return false;
    }
    $st = $conn->prepare('SELECT name, email FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }
    rl_hit($conn, 'twofa_send', $key);
    $_SESSION['twofa_last_sent'] = time();
    $code = (string) random_int(100000, 999999);
    $_SESSION['twofa_' . $purpose] = ['uid' => $uid, 'hash' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + TWOFA_TTL, 'tries' => 0, 'sent' => time()];
    if (!sendLoginCodeMail((string) $u['email'], (string) $u['name'], $code, $purpose)) {
        unset($_SESSION['twofa_' . $purpose]);
        return false;
    }
    return true;
}

/** Checks an entered code: 'ok', 'bad', 'expired' (none sent / too old) or 'locked' (too many tries). A good code is used up. */
function twofa_check_code(int $uid, string $purpose, string $input): string
{
    $k = 'twofa_' . $purpose;
    $c = $_SESSION[$k] ?? null;
    if (!is_array($c) || (int) ($c['uid'] ?? 0) !== $uid || time() > (int) $c['exp']) {
        unset($_SESSION[$k]);
        return 'expired';
    }
    if ((int) $c['tries'] >= TWOFA_MAX_TRIES) {
        unset($_SESSION[$k]);
        return 'locked';
    }
    $input = preg_replace('/\D/', '', $input);
    if (strlen($input) === 6 && password_verify($input, (string) $c['hash'])) {
        unset($_SESSION[$k]);
        return 'ok';
    }
    $_SESSION[$k]['tries'] = (int) $c['tries'] + 1;
    return 'bad';
}

function twofa_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        twofa_ensure_schema($conn);
        $conn->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow twofactor cleanup: ' . $e->getMessage());
    }
}
