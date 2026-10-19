<?php
/**
 * Admin module helpers: roles, permissions and the audit trail.
 *
 * Roles: super (Super Admin, always allowed), admin, support, user.
 * Staff = super, admin or support. users.is_admin stays 1 for every staff member so older code
 * that only checks is_admin keeps working.
 * Audit trail = user_logs rows whose action starts with "admin_" (written with logAction()).
 */
require_once __DIR__ . '/../../includes/schema.php';

const ADMIN_ROLES = ['super' => 'Super Admin', 'admin' => 'Admin', 'support' => 'Support', 'user' => 'User'];

/** perm key => label. 'SA' is not listed: it means "Super Admin only". */
const ADMIN_PERMS = [
    'view' => 'View user profiles',
    'add' => 'Add users',
    'block' => 'Block / unblock users',
    'reset' => 'Send password-reset links',
    'del' => 'Delete users',
    'export' => 'Export user list',
    'support' => 'Handle support tickets',
    'bc' => 'Send announcements',
    'cat' => 'Manage categories',
    'cur' => 'Manage currencies and rates',
    'grp' => 'Manage shared groups',
    'log' => 'View audit log',
    'backup' => 'Create and restore backups',
    'set' => 'Change system settings',
];

/** What each role may do until a Super Admin changes it. */
const ADMIN_DEFAULTS = [
    'admin' => ['view' => 1, 'add' => 0, 'block' => 1, 'reset' => 1, 'del' => 0, 'export' => 0, 'support' => 1, 'bc' => 1, 'cat' => 0, 'cur' => 0, 'grp' => 0, 'log' => 1, 'backup' => 0, 'set' => 0],
    'support' => ['view' => 1, 'add' => 0, 'block' => 0, 'reset' => 1, 'del' => 0, 'export' => 0, 'support' => 1, 'bc' => 0, 'cat' => 0, 'cur' => 0, 'grp' => 0, 'log' => 0, 'backup' => 0, 'set' => 0],
];

function admin_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!schema_has($conn, 'users', 'role')) {
            $conn->exec("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(10) NOT NULL DEFAULT 'user' AFTER `is_admin`");
            schema_has($conn, 'users', 'role', true);
            $conn->exec("UPDATE `users` SET `role` = 'super' WHERE `is_admin` = 1");
        }
        if (!schema_has($conn, 'users', 'blocked')) {
            $conn->exec("ALTER TABLE `users` ADD COLUMN `blocked` TINYINT(1) NOT NULL DEFAULT 0");
            schema_has($conn, 'users', 'blocked', true);
        }
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `admin_perms` (
              `role` VARCHAR(10) NOT NULL,
              `perm` VARCHAR(12) NOT NULL,
              `allowed` TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (`role`, `perm`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow admin schema check failed: ' . $e->getMessage());
    }
}

function admin_role_label(string $role): string
{
    return ADMIN_ROLES[$role] ?? 'User';
}

function admin_is_staff(string $role): bool
{
    return in_array($role, ['super', 'admin', 'support'], true);
}

/** Permission table for Admin and Support: defaults overridden by saved rows. */
function admin_matrix(PDO $conn, bool $refresh = false): array
{
    static $m = null;
    if ($m !== null && !$refresh) {
        return $m;
    }
    $m = ADMIN_DEFAULTS;
    try {
        foreach ($conn->query('SELECT role, perm, allowed FROM admin_perms')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($m[$r['role']][$r['perm']])) {
                $m[$r['role']][$r['perm']] = (int) $r['allowed'];
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow permissions read failed: ' . $e->getMessage());
    }
    return $m;
}

/** May this staff member do $perm? 'SA' = Super Admin only. */
function admin_can(PDO $conn, string $role, string $perm): bool
{
    if ($role === 'super') {
        return true;
    }
    if ($perm === 'SA' || !isset(ADMIN_PERMS[$perm])) {
        return false;
    }
    return !empty(admin_matrix($conn)[$role][$perm]);
}

/** Saves one permission switch for admin or support. */
function admin_set_perm(PDO $conn, string $role, string $perm, bool $allowed): bool
{
    if (!isset(ADMIN_DEFAULTS[$role]) || !isset(ADMIN_PERMS[$perm])) {
        return false;
    }
    $conn->prepare('INSERT INTO admin_perms (role, perm, allowed) VALUES (?,?,?) ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)')
        ->execute([$role, $perm, $allowed ? 1 : 0]);
    admin_matrix($conn, true);
    return true;
}

/** Number of Super Admins (the last one can never be demoted or deleted). */
function admin_super_count(PDO $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) FROM users WHERE role = 'super'")->fetchColumn();
}

/** Sets a user's role and keeps is_admin in step. */
function admin_set_role(PDO $conn, int $userId, string $role): bool
{
    if (!isset(ADMIN_ROLES[$role])) {
        return false;
    }
    $conn->prepare('UPDATE users SET role = ?, is_admin = ? WHERE id = ?')->execute([$role, admin_is_staff($role) ? 1 : 0, $userId]);
    return true;
}

/** Writes one audit line (shown in the Audit log page). */
function admin_audit(PDO $conn, int $actorId, string $kind, string $text): void
{
    logAction($conn, $actorId, 'admin_' . preg_replace('/[^a-z_]/', '', $kind), $text);
}

/**
 * May this staff member act on this account (block, reset, change, delete)?
 * Never on yourself. A Super Admin may act on anybody; Admin and Support only on plain users.
 */
function admin_can_manage(array $actor, array $target): bool
{
    if ((int) $actor['id'] === (int) $target['id']) {
        return false;
    }
    return $actor['role'] === 'super' || ($target['role'] ?? 'user') === 'user';
}

/** A random password that satisfies the password policy (shown once to the staff member). */
function admin_temp_password(): string
{
    $pick = fn(string $set) => $set[random_int(0, strlen($set) - 1)];
    $chars = [$pick('ABCDEFGHJKLMNPQRSTUVWXYZ'), $pick('abcdefghijkmnpqrstuvwxyz'), $pick('23456789'), $pick('!@#$%&*?')];
    $all = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    while (count($chars) < 12) {
        $chars[] = $pick($all);
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/** Spreadsheet programs run cells that start with = + - @ : neutralise them in CSV exports. */
function admin_csv_cell($v): string
{
    $v = (string) $v;
    return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
}

/** Audit actions: action (without the admin_ prefix) => [group, label]. */
function admin_audit_kinds(): array
{
    return [
        'add_user' => ['users', 'Added user'], 'edit_user' => ['users', 'Edited user'], 'block_user' => ['users', 'Blocked user'],
        'unblock_user' => ['users', 'Unblocked user'], 'delete_user' => ['users', 'Deleted user'], 'reset_password' => ['users', 'Temporary password'],
        'export_users' => ['users', 'Exported users'], 'set_role' => ['roles', 'Changed role'], 'set_perms' => ['roles', 'Changed permissions'],
        'ticket_reply' => ['support', 'Support reply'], 'ticket_close' => ['support', 'Closed request'], 'ticket_reopen' => ['support', 'Reopened request'],
    ];
}

function admin_audit_label(string $action): string
{
    $k = preg_replace('/^admin_/', '', $action);
    return admin_audit_kinds()[$k][1] ?? ucfirst(str_replace('_', ' ', $k));
}

/** Audit groups for the filter chips. */
function admin_audit_groups(): array
{
    return ['all' => 'All', 'users' => 'Users', 'roles' => 'Roles', 'support' => 'Support', 'other' => 'Other'];
}

/** SQL fragment + params that select the audit rows of one group (or all). */
function admin_audit_where(string $group): array
{
    $known = admin_audit_kinds();
    $in = fn(array $keys) => ["l.action IN (" . implode(',', array_fill(0, count($keys), '?')) . ")", array_map(fn($k) => 'admin_' . $k, $keys)];
    if (isset(admin_audit_groups()[$group]) && $group !== 'all' && $group !== 'other') {
        return $in(array_keys(array_filter($known, fn($v) => $v[0] === $group)));
    }
    if ($group === 'other') {
        [$sql, $p] = $in(array_keys($known));
        return ["l.action LIKE 'admin\\_%' AND NOT ($sql)", $p];
    }
    return ["l.action LIKE 'admin\\_%'", []];
}
