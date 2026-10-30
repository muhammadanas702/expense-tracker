<?php
/**
 * Google sign-in (OpenID Connect, authorization-code flow with PKCE).
 *
 *  auth/google.php           starts it: stores state + PKCE verifier in the session, sends the browser to Google
 *  auth/google-callback.php  Google returns here: state check, code exchange (server to server over TLS),
 *                            claims check, then google_user_for_claims() finds, links or creates the account
 *
 * Rules: only a Google-VERIFIED e-mail is trusted; an existing ExpenseFlow account with that e-mail is linked to
 * the Google identity (the same person proved the address); blocked accounts and blocked IPs are refused; new
 * accounts respect the "sign-ups closed" switch. Google-created accounts get a random password nobody knows
 * (the person can set a real one with "Forgot password").
 */
require_once __DIR__ . '/../../config/google.php';
require_once __DIR__ . '/../../includes/schema.php';
require_once __DIR__ . '/../../includes/assets.php';

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

/** Google's token endpoint. Only the tests override it (EXPENSEFLOW_GOOGLE_TOKEN_URL) to talk to a local fake. */
function google_token_url(): string
{
    return (string) (getenv('EXPENSEFLOW_GOOGLE_TOKEN_URL') ?: GOOGLE_TOKEN_URL);
}
const GOOGLE_STATE_TTL = 600; // seconds the person has to finish on Google's side

function oauth_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    // user_id has no foreign key on purpose: some hosts refuse it (table type / column type differs) and that made the
    // table, and with it the whole sign-in, fail. oauth_on_user_deleted() removes the rows instead.
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `user_identities` (
              `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id`    INT UNSIGNED NOT NULL,
              `provider`   VARCHAR(10)  NOT NULL,
              `subject`    VARCHAR(64)  NOT NULL,
              `email`      VARCHAR(190) NOT NULL DEFAULT '',
              `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_identity` (`provider`, `subject`),
              KEY `idx_identity_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('ef_schema_mark')) {
            ef_schema_mark(__FUNCTION__, __FILE__);
        }
    } catch (Throwable $e) {
        error_log('oauth schema: ' . $e->getMessage());
    }
}

/** Account deleted: forget its Google links (no foreign key, so this is done here; called by shared_on_user_deleted). */
function oauth_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        oauth_ensure_schema($conn);
        $conn->prepare('DELETE FROM user_identities WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('oauth cleanup: ' . $e->getMessage());
    }
}

/** True inside the Android/Windows shells: Google refuses sign-in from embedded web views. */
function google_in_app_shell(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return strpos($ua, 'ExpenseFlowApp/') !== false || strpos($ua, 'ExpenseFlowDesktop/') !== false || strpos($ua, '; wv)') !== false;
}

function google_available(): bool
{
    return google_settings() !== null && !google_in_app_shell();
}

function google_redirect_uri(): string
{
    return ef_url('auth/google-callback.php');
}

function google_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Builds the Google URL and remembers state + PKCE verifier in the session. */
function google_start_url(string $intent): string
{
    $cfg = google_settings();
    $state = bin2hex(random_bytes(16));
    $verifier = google_b64url(random_bytes(32));
    $_SESSION['g_oauth'] = ['state' => $state, 'verifier' => $verifier, 't' => time(), 'intent' => $intent === 'signup' ? 'signup' : 'login'];
    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => $cfg['client_id'],
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'code_challenge' => google_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Checks the state sent back by Google. Returns the saved data once (it is single use) or null.
 * $why says what went wrong: no_state (the return URL carried none, the saved one is kept), no_session (the browser
 * did not send the session cookie back), mismatch, old.
 */
function google_take_state(string $state, ?string &$why = null): ?array
{
    $why = null;
    $saved = $_SESSION['g_oauth'] ?? null;
    if ($state === '') {
        $why = 'no_state';
        return null; // keep the saved state: a stray request without parameters must not break the real one
    }
    unset($_SESSION['g_oauth']);
    if (!is_array($saved)) {
        $why = 'no_session';
        return null;
    }
    if (!hash_equals((string) $saved['state'], $state)) {
        $why = 'mismatch';
        return null;
    }
    if (time() - (int) $saved['t'] > GOOGLE_STATE_TTL) {
        $why = 'old';
        return null;
    }
    return $saved;
}

/** POST form fields to Google and decode the JSON answer. $err gets a short reason when nothing usable came back. */
function google_post(string $url, array $fields, ?string &$err = null): ?array
{
    $body = http_build_query($fields);
    $raw = false;
    $err = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = 'network: ' . substr((string) curl_error($ch), 0, 80);
        }
        curl_close($ch);
    } elseif (!ini_get('allow_url_fopen')) {
        $err = 'network: neither curl nor allow_url_fopen is available';
    } else {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 12, 'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n", 'content' => $body]]));
    }
    if ($raw === false && $err === null) {
        $err = 'network: no answer';
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($json) && isset($json['error'])) {
        $err = 'google: ' . substr((string) $json['error'], 0, 40) . (isset($json['error_description']) ? ' - ' . substr((string) $json['error_description'], 0, 80) : '');
    } elseif (!is_array($json) && $err === null) {
        $err = 'bad answer';
    }
    return is_array($json) ? $json : null;
}

/** Exchanges the one-time code for the signed-in person's claims (null on any failure). */
function google_exchange_code(string $code, string $verifier, ?string &$why = null): ?array
{
    $cfg = google_settings();
    $why = null;
    $res = google_post(google_token_url(), [
        'code' => $code, 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
        'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
    ], $why);
    if (!$res || empty($res['id_token']) || !is_string($res['id_token'])) {
        $why = $why ?? 'no id_token';
        return null;
    }
    // The id_token came straight from Google's token endpoint over TLS, so its payload is trusted after the claim checks below.
    $parts = explode('.', $res['id_token']);
    $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    if (!is_array($claims)) {
        $why = 'unreadable id_token';
        return null;
    }
    return $claims;
}

/** Null when the claims are acceptable, otherwise a short reason (never shown to the visitor). */
function google_claims_error(array $c, string $clientId, ?int $now = null): ?string
{
    $now = $now ?? time();
    if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return 'bad issuer';
    }
    if (($c['aud'] ?? '') !== $clientId) {
        return 'bad audience';
    }
    if ((int) ($c['exp'] ?? 0) < $now) {
        return 'expired';
    }
    $sub = (string) ($c['sub'] ?? '');
    if ($sub === '' || strlen($sub) > 64) {
        return 'no subject';
    }
    $email = (string) ($c['email'] ?? '');
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'no email';
    }
    $ev = $c['email_verified'] ?? false;
    if (!($ev === true || $ev === 'true')) {
        return 'email not verified';
    }
    return null;
}

/**
 * The existing account for an e-mail address. An exact match wins. For Gmail, the dots and "+tag" in the part before
 * the @ do not change the mailbox (a.nas+x@gmail.com is anas@gmail.com, and googlemail.com is gmail.com), so those
 * spellings find the same person instead of creating a second account.
 */
function google_find_by_email(PDO $conn, string $email): ?array
{
    $email = strtolower(trim($email));
    $st = $conn->prepare('SELECT id, name, blocked FROM users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if (!in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        return null;
    }
    $key = str_replace('.', '', explode('+', $local, 2)[0]);
    $st = $conn->prepare("SELECT id, name, blocked FROM users
        WHERE SUBSTRING_INDEX(email, '@', -1) IN ('gmail.com','googlemail.com')
          AND REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(email, '@', 1), '+', 1), '.', '') = ?
        ORDER BY id LIMIT 1");
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Finds, links or creates the ExpenseFlow account for verified Google claims.
 * @return array{0:?array,1:string} [user row (id, name) or null, error code: '', 'blocked', 'closed', 'failed']
 */
function google_user_for_claims(PDO $conn, array $c, bool $signupsOpen): array
{
    oauth_ensure_schema($conn);
    $sub = (string) $c['sub'];
    $email = strtolower(trim((string) $c['email']));

    $st = $conn->prepare("SELECT u.id, u.name, u.blocked FROM user_identities i JOIN users u ON u.id = i.user_id WHERE i.provider = 'google' AND i.subject = ?");
    $st->execute([$sub]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $user = google_find_by_email($conn, $email);
        if (!$user) {
            if (!$signupsOpen) {
                return [null, 'closed'];
            }
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '') {
                $name = ucfirst((string) strstr($email, '@', true));
            }
            $name = mb_substr($name, 0, 100, 'UTF-8');
            try {
                $conn->prepare('INSERT INTO users(name,email,password) VALUES(?,?,?)')
                    ->execute([$name, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                $user = ['id' => (int) $conn->lastInsertId(), 'name' => $name, 'blocked' => 0, 'created' => true];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    error_log('Google sign-up failed: ' . $e->getMessage());
                    $GLOBALS['google_fail_detail'] = 'signup sql ' . $e->getCode();
                    return [null, 'failed'];
                }
                $user = google_find_by_email($conn, $email); // lost a race with another request
                if (!$user) {
                    return [null, 'failed'];
                }
            }
        }
        try {
            $conn->prepare("INSERT INTO user_identities(user_id,provider,subject,email) VALUES(?, 'google', ?, ?)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), email = VALUES(email)")->execute([(int) $user['id'], $sub, $email]);
        } catch (PDOException $e) {
            error_log('Google identity link failed: ' . $e->getMessage());
            $GLOBALS['google_fail_detail'] = 'identity sql ' . $e->getCode();
            return [null, 'failed'];
        }
    }
    if ((int) ($user['blocked'] ?? 0) === 1) {
        return [null, 'blocked'];
    }
    return [$user, ''];
}

/**
 * The mark inside the button. If the official Google "G" file is at assets/icons/google-g.svg it is used
 * (download it from Google's sign-in branding guidelines); until then a plain neutral letter is shown.
 */
function google_mark(): string
{
    if (is_file(__DIR__ . '/../../assets/icons/google-g.svg')) {
        return '<img class="gimg" src="' . e(ef_asset('assets/icons/google-g.svg')) . '" alt="" width="20" height="20">';
    }
    return '<span class="gmark" aria-hidden="true">G</span>';
}

/** The "Continue with Google" button + "or" divider. Empty string when Google sign-in is not available. */
function google_button(string $intent): string
{
    if (!google_available()) {
        return '';
    }
    $url = ef_url('auth/google.php' . ($intent === 'signup' ? '?intent=signup' : ''));
    $label = $intent === 'signup' ? 'Sign up with Google' : 'Continue with Google';
    $note = $intent === 'signup'
        ? '<p class="gnote">By continuing you agree to the <a href="' . e(ef_url('terms.php')) . '">Terms of Use</a> and the <a href="' . e(ef_url('privacy.php')) . '">Privacy Policy</a>.</p>'
        : '';
    return '<a class="gbtn" href="' . e($url) . '">' . google_mark() . '' . e($label) . '</a>' . $note . '<div class="gor"><span>or use your email</span></div>';
}
