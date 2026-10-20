<?php
/**
 * Sign-up email confirmation. Nothing is written to the database until the person types the 6-digit code that
 * was emailed to them, so only addresses they can really read get an account. The pending sign-up lives in the
 * visitor's own session (the password is already hashed there).
 */
const SIGNUP_CODE_TTL = 600;        // 10 minutes
const SIGNUP_MAX_ATTEMPTS = 5;      // wrong codes before the code is thrown away
const SIGNUP_RESEND_WAIT = 60;      // seconds between emails
const SIGNUP_MAX_SENDS = 4;         // emails per pending sign-up

/** Throw-away address services (a short list of the most common ones). */
const SIGNUP_DISPOSABLE = [
    'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'sharklasers.com', 'grr.la', '10minutemail.com', '10minutemail.net',
    'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmail.net', 'tempmailo.com', 'throwawaymail.com', 'yopmail.com', 'yopmail.net', 'yopmail.fr',
    'getnada.com', 'nada.email', 'maildrop.cc', 'dispostable.com', 'trashmail.com', 'trashmail.net', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com',
    'mohmal.com', 'emailondeck.com', 'mytemp.email', 'spamgourmet.com', 'tempinbox.com', 'moakt.com', 'discard.email', 'mail.tm', 'burnermail.io',
    'inboxkitten.com', 'mailcatch.com', 'spambox.us', 'fakemail.net', 'tmpmail.org', 'tmpmail.net', 'emailfake.com', 'crazymailing.com', 'tempr.email',
];

/** Lower-cased address as typed (spaces removed) or '' when it is not a plausible address. */
function signup_normalize_email(string $raw): string
{
    $e = strtolower(trim($raw));
    if ($e === '' || strlen($e) > 190 || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    [$local, $domain] = explode('@', $e, 2) + [1 => ''];
    // Plain, public mailbox shape: a dotted domain with letters in the last part, no IP literals.
    if ($local === '' || strlen($local) > 64 || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,24}$/', $domain) || str_contains($local, '..')) {
        return '';
    }
    return $e;
}

function signup_is_disposable(string $email): bool
{
    $d = substr(strrchr($email, '@') ?: '', 1);
    foreach (SIGNUP_DISPOSABLE as $bad) {
        if ($d === $bad || str_ends_with($d, '.' . $bad)) {
            return true;
        }
    }
    return false;
}

/**
 * Can this domain receive mail? true / false, or null when we cannot tell (no DNS function, DNS down).
 * $resolver(domain, type) => bool|null is injectable for tests.
 */
function signup_domain_receives_mail(string $domain, ?callable $resolver = null): ?bool
{
    $resolver ??= function (string $d, string $type): ?bool {
        return function_exists('checkdnsrr') ? @checkdnsrr($d . '.', $type) : null;
    };
    if ($resolver($domain, 'MX') === true || $resolver($domain, 'A') === true || $resolver($domain, 'AAAA') === true) {
        return true;
    }
    // "No records" only counts when DNS itself works: a well-known domain must resolve.
    $canary = $resolver('gmail.com', 'MX');
    return $canary === true ? false : null;
}

/** Returns an error text when the address cannot be used, otherwise null. */
function signup_email_problem(string $email, ?callable $resolver = null): ?string
{
    if ($email === '') {
        return 'Please enter a valid email address.';
    }
    if (signup_is_disposable($email)) {
        return 'Please use a real email address that you check regularly (temporary email services are not allowed).';
    }
    if (($hint = signup_typo_hint($email)) !== null) {
        return 'Did you mean ' . $hint . '? Please correct the address.';
    }
    $domain = substr(strrchr($email, '@'), 1);
    if (signup_domain_receives_mail($domain, $resolver) === false) {
        return 'That email domain cannot receive mail. Please check the spelling.';
    }
    return null;
}

/** Common typos of big providers, so people can fix them before waiting for a code that never comes. */
function signup_typo_hint(string $email): ?string
{
    $d = substr(strrchr($email, '@') ?: '', 1);
    $fix = ['gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmil.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'yaho.com' => 'yahoo.com', 'yahooo.com' => 'yahoo.com', 'outlok.com' => 'outlook.com', 'iclod.com' => 'icloud.com'];
    return isset($fix[$d]) ? substr($email, 0, -strlen($d)) . $fix[$d] : null;
}

function signup_code_new(): string
{
    return (string) random_int(100000, 999999);
}

/** Puts a pending sign-up into the session. $code is the plain code that is about to be emailed. */
function signup_pending_set(string $name, string $email, string $passwordHash, string $code): void
{
    $_SESSION['signup_pending'] = [
        'name' => $name, 'email' => $email, 'hash' => $passwordHash,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + SIGNUP_CODE_TTL,
        'attempts' => 0, 'sent_at' => time(), 'sends' => 1,
    ];
}

function signup_pending(): ?array
{
    $p = $_SESSION['signup_pending'] ?? null;
    return is_array($p) && isset($p['email'], $p['name'], $p['hash']) ? $p : null;
}

function signup_pending_clear(): void
{
    unset($_SESSION['signup_pending']);
}

/** "ab•••@gmail.com" */
function signup_mask(string $email): string
{
    $at = strpos($email, '@');
    return $at === false ? '' : substr($email, 0, min(2, $at)) . '•••' . substr($email, $at);
}

/** [ok, error]. Checks the typed code against the pending sign-up and counts wrong tries. */
function signup_check_code(string $typed): array
{
    $p = signup_pending();
    if (!$p) {
        return [false, 'Your sign-up expired. Please start again.'];
    }
    if ((int) $p['attempts'] >= SIGNUP_MAX_ATTEMPTS) {
        $_SESSION['signup_pending']['code_hash'] = '';
        return [false, 'Too many wrong attempts. Please ask for a new code.'];
    }
    if ($p['code_hash'] === '' || time() > (int) $p['expires']) {
        return [false, 'This code has expired. Please ask for a new one.'];
    }
    $digits = preg_replace('/\D/', '', $typed);
    if (strlen($digits) === 6 && password_verify($digits, $p['code_hash'])) {
        return [true, null];
    }
    $_SESSION['signup_pending']['attempts'] = (int) $p['attempts'] + 1;
    return [false, 'That code is not right. Please check it and try again.'];
}

/** Seconds until another email may be sent (0 = now). */
function signup_resend_wait(array $p): int
{
    return max(0, SIGNUP_RESEND_WAIT - (time() - (int) $p['sent_at']));
}
