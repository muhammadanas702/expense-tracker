<?php
/**
 * Usernames (Part 111c): an optional short name people can use to find you for a friend request, instead of an e-mail address.
 * 3-20 characters: lower-case letters, digits, "_" and ".". Unique. Nobody can look up an e-mail from a username.
 */
const FRIENDS_RESERVED_USERNAMES = ['admin', 'administrator', 'support', 'expenseflow', 'root', 'system', 'help', 'official', 'staff', 'moderator'];

function friends_username_ensure(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->query('SELECT username FROM users LIMIT 1');
    } catch (Throwable $e) {
        try {
            $conn->exec('ALTER TABLE `users` ADD COLUMN `username` VARCHAR(20) NULL DEFAULT NULL');
            $conn->exec('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_username` (`username`)');
        } catch (Throwable $e2) {
            error_log('username column: ' . $e2->getMessage());
        }
    }
}

/** Cleans what the person typed ("@Ali.Raza " -> "ali.raza"); null when it is not a valid username. */
function friends_username_clean($raw): ?string
{
    $u = strtolower(trim((string) $raw));
    $u = ltrim($u, '@');
    if (!preg_match('/^[a-z0-9][a-z0-9_.]{2,19}$/', $u) || preg_match('/[_.]{2}/', $u) || preg_match('/[_.]$/', $u)) {
        return null;
    }
    return $u;
}

/** Saves (or, with an empty value, removes) the username. @return array{ok:bool,msg:string} */
function friends_username_set(PDO $conn, int $userId, string $raw): array
{
    friends_username_ensure($conn);
    if (trim($raw) === '') {
        $conn->prepare('UPDATE users SET username = NULL WHERE id = ?')->execute([$userId]);
        return ['ok' => true, 'msg' => 'Username removed.'];
    }
    $u = friends_username_clean($raw);
    if ($u === null) {
        return ['ok' => false, 'msg' => 'Username must be 3 to 20 characters: letters, digits, "_" or ".", starting with a letter or digit, and not ending with "_" or ".".'];
    }
    if (in_array($u, FRIENDS_RESERVED_USERNAMES, true)) {
        return ['ok' => false, 'msg' => 'That username is reserved. Please choose another one.'];
    }
    try {
        $st = $conn->prepare('UPDATE users SET username = ? WHERE id = ? AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE username = ? AND id <> ?) t)');
        $st->execute([$u, $userId, $u, $userId]);
        $cur = $conn->prepare('SELECT username FROM users WHERE id = ?');
        $cur->execute([$userId]);
        if ($cur->fetchColumn() !== $u) {
            return ['ok' => false, 'msg' => 'That username is already taken. Please choose another one.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'That username is already taken. Please choose another one.'];
    }
    return ['ok' => true, 'msg' => 'Username saved: @' . $u];
}

function friends_username_of(PDO $conn, int $userId): ?string
{
    friends_username_ensure($conn);
    $st = $conn->prepare('SELECT username FROM users WHERE id = ?');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return is_string($v) && $v !== '' ? $v : null;
}
