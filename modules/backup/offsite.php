<?php
/**
 * Off-site backup (Part 104). The newest backup is ENCRYPTED on the server (AES-256-GCM, same format as the encrypted
 * download, open it with `php tools/decrypt-backup.php file.enc`) and uploaded to a PRIVATE GitHub repository through the
 * GitHub contents API, so the data survives if the free host suspends the account.
 * Settings live in storage/offsite.json (storage is closed to the web, file mode 0600): repo, token and passphrase.
 * The token should be a fine-grained token limited to that one repository with "Contents: read and write".
 * Everything here fails soft: a problem is remembered and shown in the admin panel, it never breaks a page.
 */
require_once __DIR__ . '/lib.php';

const OFFSITE_KEEP = 30;
const OFFSITE_MAX_BYTES = 40 * 1048576;

function offsite_file(): string
{
    return dirname(__DIR__, 2) . '/storage/offsite.json';
}

function offsite_defaults(): array
{
    return ['on' => false, 'repo' => '', 'token' => '', 'pass' => '', 'last_ok' => 0, 'last_name' => '', 'last_error' => '', 'last_try' => 0];
}

function offsite_config(): array
{
    $c = @json_decode((string) @file_get_contents(offsite_file()), true);
    return is_array($c) ? array_merge(offsite_defaults(), $c) : offsite_defaults();
}

function offsite_save(array $c): bool
{
    $f = offsite_file();
    $ok = @file_put_contents($f, json_encode($c, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    @chmod($f, 0600);
    return $ok;
}

function offsite_valid_repo($r): bool
{
    return is_string($r) && preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $r) === 1;
}

function offsite_ready(?array $c = null): bool
{
    $c = $c ?? offsite_config();
    return $c['on'] && offsite_valid_repo($c['repo']) && $c['token'] !== '' && strlen($c['pass']) >= 8;
}

function offsite_api_base(): string
{
    return rtrim(getenv('EXPENSEFLOW_GITHUB_API') ?: 'https://api.github.com', '/');
}

/** One GitHub API call. Returns [http status (0 = no answer), decoded body|null, error text]. */
function offsite_http(string $method, string $path, ?array $json, string $token): array
{
    if (!function_exists('curl_init')) {
        return [0, null, 'The curl extension is not available on this server.'];
    }
    $ch = curl_init(offsite_api_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'User-Agent: ExpenseFlow-backup', 'X-GitHub-Api-Version: 2022-11-28', 'Content-Type: application/json'],
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$code, is_string($raw) ? json_decode($raw, true) : null, $err];
}

function offsite_explain(int $code, string $err): string
{
    if ($code === 0) {
        return 'Could not reach GitHub' . ($err !== '' ? ' (' . $err . ')' : '') . '. Some free hosts block outgoing connections.';
    }
    if ($code === 401) {
        return 'GitHub did not accept the token. It may be wrong or expired.';
    }
    if ($code === 403) {
        return 'GitHub refused this token. It needs "Contents: Read and write" on the backup repository.';
    }
    if ($code === 404) {
        return 'GitHub cannot find the repository, or the token has no access to it. Check the name (owner/repository).';
    }
    return 'GitHub answered with error ' . $code . '.';
}

/** Checks the saved settings against GitHub without uploading anything. Returns [ok, message]. */
function offsite_test(?array $c = null): array
{
    $c = $c ?? offsite_config();
    if (!offsite_valid_repo($c['repo']) || $c['token'] === '') {
        return [false, 'Type the repository as owner/name and paste the token.'];
    }
    if (strlen($c['pass']) < 8) {
        return [false, 'The passphrase needs at least 8 characters.'];
    }
    if (!function_exists('openssl_encrypt')) {
        return [false, 'OpenSSL is not available on this server, so backups cannot be encrypted.'];
    }
    [$code, $body, $err] = offsite_http('GET', '/repos/' . $c['repo'], null, $c['token']);
    if ($code !== 200 || !is_array($body)) {
        return [false, offsite_explain($code, $err)];
    }
    if (empty($body['private'])) {
        return [false, 'This repository is public. Make it Private first (Settings > Danger zone).'];
    }
    return [true, 'Connected to the private repository ' . ($body['full_name'] ?? $c['repo']) . '.'];
}

/** Encrypts and uploads one backup file, then trims old copies. Returns [ok, message]. */
function offsite_push(string $name): array
{
    $c = offsite_config();
    if (!offsite_ready($c)) {
        return [false, 'Off-site backup is not switched on.'];
    }
    $n = backup_valid_name($name);
    $path = $n ? backup_dir() . '/' . $n : '';
    if ($n === null || !is_file($path)) {
        return [false, 'That backup file does not exist.'];
    }
    if (filesize($path) > OFFSITE_MAX_BYTES) {
        return [false, 'The backup is larger than ' . backup_size_label(OFFSITE_MAX_BYTES) . ', which is too big to upload this way.'];
    }
    $blob = backup_encrypt((string) file_get_contents($path), $c['pass']);
    if ($blob === null) {
        return offsite_fail($c, 'The backup could not be encrypted (check the passphrase and OpenSSL).');
    }
    $repoPath = 'backups/' . $n . '.enc';
    [$code, $body, $err] = offsite_http('PUT', '/repos/' . $c['repo'] . '/contents/' . $repoPath, ['message' => 'Backup ' . $n, 'content' => base64_encode($blob)], $c['token']);
    if ($code === 422) { // the same file is already there
        $code = 200;
    }
    if ($code !== 200 && $code !== 201) {
        return offsite_fail($c, offsite_explain($code, $err));
    }
    $c['last_ok'] = time();
    $c['last_name'] = $n;
    $c['last_error'] = '';
    $c['last_try'] = time();
    offsite_save($c);
    offsite_trim($c);
    return [true, 'Uploaded ' . $n . ' (encrypted) to ' . $c['repo'] . '.'];
}

function offsite_fail(array $c, string $msg): array
{
    $c['last_error'] = $msg;
    $c['last_try'] = time();
    offsite_save($c);
    return [false, $msg];
}

/** Keeps the newest OFFSITE_KEEP copies in the repository. Best effort. */
function offsite_trim(array $c): void
{
    try {
        [$code, $list] = offsite_http('GET', '/repos/' . $c['repo'] . '/contents/backups', null, $c['token']);
        if ($code !== 200 || !is_array($list)) {
            return;
        }
        $files = array_values(array_filter($list, fn($f) => is_array($f) && isset($f['name'], $f['sha'], $f['path']) && preg_match('/^backup-.*\.enc$/', $f['name'])));
        usort($files, fn($a, $b) => strcmp($b['name'], $a['name']));
        foreach (array_slice($files, OFFSITE_KEEP) as $f) {
            offsite_http('DELETE', '/repos/' . $c['repo'] . '/contents/' . $f['path'], ['message' => 'Remove old backup', 'sha' => $f['sha']], $c['token']);
        }
    } catch (Throwable $e) {
    }
}

/**
 * Called on the admin overview after the automatic backup: uploads the newest backup if it was not uploaded yet.
 * Never throws; returns [ok, message] when it tried, otherwise null.
 */
function offsite_maybe(): ?array
{
    try {
        $c = offsite_config();
        if (!offsite_ready($c)) {
            return null;
        }
        $newest = backup_list()[0]['name'] ?? null;
        if ($newest === null || $c['last_name'] === $newest) {
            return null;
        }
        if ($c['last_error'] !== '' && time() - $c['last_try'] < 1800) {
            return null; // do not hammer GitHub while it is failing
        }
        if (!backup_prepare_dir()) {
            return null;
        }
        $lock = @fopen(backup_dir() . '/.offsite.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            return offsite_push($newest);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow offsite backup: ' . $e->getMessage());
        return null;
    }
}

/** Plain facts for the admin pages: state is off | ok | late | failed. */
function offsite_status(): array
{
    $c = offsite_config();
    if (!offsite_ready($c)) {
        return ['state' => 'off', 'text' => 'Not set up: backups exist only on this server.', 'config' => $c];
    }
    if ($c['last_error'] !== '') {
        return ['state' => 'failed', 'text' => 'The last upload failed: ' . $c['last_error'], 'config' => $c];
    }
    if ($c['last_ok'] === 0) {
        return ['state' => 'late', 'text' => 'Switched on. Nothing has been uploaded yet.', 'config' => $c];
    }
    $days = (int) floor((time() - $c['last_ok']) / 86400);
    if ($days >= 3) {
        return ['state' => 'late', 'text' => 'The last upload was ' . $days . ' days ago. Open the admin overview to send a fresh one.', 'config' => $c];
    }
    return ['state' => 'ok', 'text' => 'Last upload ' . date('d M Y, H:i', $c['last_ok']) . ' (' . $c['last_name'] . ').', 'config' => $c];
}
