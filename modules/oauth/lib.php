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
const GOOGLE_STATE_TTL = 600; // seconds the person has to finish on Google's side

function oauth_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
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
              KEY `idx_identity_user` (`user_id`),
              CONSTRAINT `fk_identity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('ef_schema_mark')) {
            ef_schema_mark(__FUNCTION__, __FILE__);
        }
    } catch (Throwable $e) {
        error_log('oauth schema: ' . $e->getMessage());
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

/** Checks the state sent back by Google. Returns the saved data once (it is single use) or null. */
function google_take_state(string $state): ?array
{
    $saved = $_SESSION['g_oauth'] ?? null;
    unset($_SESSION['g_oauth']);
    if (!is_array($saved) || $state === '' || !hash_equals((string) $saved['state'], $state) || time() - (int) $saved['t'] > GOOGLE_STATE_TTL) {
        return null;
    }
    return $saved;
}

/** POST form fields to Google and decode the JSON answer. */
function google_post(string $url, array $fields): ?array
{
    $body = http_build_query($fields);
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 12, 'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n", 'content' => $body]]));
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : null;
}

/** Exchanges the one-time code for the signed-in person's claims (null on any failure). */
function google_exchange_code(string $code, string $verifier): ?array
{
    $cfg = google_settings();
    $res = google_post(GOOGLE_TOKEN_URL, [
        'code' => $code, 'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
        'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code', 'code_verifier' => $verifier,
    ]);
    if (!$res || empty($res['id_token']) || !is_string($res['id_token'])) {
        return null;
    }
    // The id_token came straight from Google's token endpoint over TLS, so its payload is trusted after the claim checks below.
    $parts = explode('.', $res['id_token']);
    if (count($parts) !== 3) {
        return null;
    }
    $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
    return is_array($claims) ? $claims : null;
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
        $st = $conn->prepare('SELECT id, name, blocked FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
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
                    return [null, 'failed'];
                }
                $st = $conn->prepare('SELECT id, name, blocked FROM users WHERE email = ?'); // lost a race with another request
                $st->execute([$email]);
                $user = $st->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    return [null, 'failed'];
                }
            }
        }
        try {
            $conn->prepare("INSERT IGNORE INTO user_identities(user_id,provider,subject,email) VALUES(?, 'google', ?, ?)")->execute([(int) $user['id'], $sub, $email]);
        } catch (PDOException $e) {
            error_log('Google identity link failed: ' . $e->getMessage());
            return [null, 'failed'];
        }
    }
    if ((int) ($user['blocked'] ?? 0) === 1) {
        return [null, 'blocked'];
    }
    return [$user, ''];
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
    return '<a class="gbtn" href="' . e($url) . '"><span class="gmark" aria-hidden="true">G</span>' . e($label) . '</a>' . $note . '<div class="gor"><span>or use your email</span></div>';
}
