<?php
/**
 * Shared module helpers: groups, members, invite links.
 * This module only touches the shared_* tables (and reads user names from users).
 * Later parts add expenses/settlements in their own tables and hook into
 * shared_delete_group() / shared_on_user_deleted() for cleanup.
 */
const SHARED_MAX_GROUPS_PER_USER = 30;
const SHARED_MAX_MEMBERS = 50;

/** Group icon keys (all exist in includes/icons.php) with a label. */
function shared_icons(): array
{
    return ['users' => 'Friends', 'mtn' => 'Trip', 'home' => 'Home', 'gift' => 'Party', 'heart' => 'Family', 'food' => 'Food', 'car' => 'Travel', 'case' => 'Work', 'bag' => 'Shopping'];
}

function shared_icon_key($value): string
{
    return is_string($value) && isset(shared_icons()[$value]) ? $value : 'users';
}

function shared_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_groups` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(60) NOT NULL,
              `icon` VARCHAR(12) NOT NULL DEFAULT 'users',
              `owner_id` INT UNSIGNED NOT NULL,
              `invite_code` CHAR(32) NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_shared_groups_code` (`invite_code`),
              KEY `idx_shared_groups_owner` (`owner_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_members` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `role` VARCHAR(10) NOT NULL DEFAULT 'member',
              `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_shared_members` (`group_id`, `user_id`),
              KEY `idx_shared_members_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow shared schema check failed: ' . $e->getMessage());
    }
}

/** A group the user belongs to, or null (also null when it does not exist: never reveals other groups). */
function shared_group_for(PDO $conn, int $groupId, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT g.*, m.role AS my_role FROM shared_groups g
         JOIN shared_members m ON m.group_id = g.id AND m.user_id = ?
         WHERE g.id = ?'
    );
    $stmt->execute([$userId, $groupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** All groups of a user with member counts, newest first. */
function shared_groups_of(PDO $conn, int $userId): array
{
    $stmt = $conn->prepare(
        'SELECT g.id, g.name, g.icon, g.owner_id, m.role AS my_role,
                (SELECT COUNT(*) FROM shared_members x WHERE x.group_id = g.id) AS member_count
         FROM shared_groups g JOIN shared_members m ON m.group_id = g.id AND m.user_id = ?
         ORDER BY g.id DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Members of a group (caller must already have checked membership). Owner first. */
function shared_members_of(PDO $conn, int $groupId): array
{
    $stmt = $conn->prepare(
        'SELECT u.id, u.name, m.role, m.joined_at FROM shared_members m JOIN users u ON u.id = m.user_id
         WHERE m.group_id = ? ORDER BY (m.role = \'owner\') DESC, m.id ASC'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function shared_new_code(): string
{
    return bin2hex(random_bytes(16)); // 32 hex chars
}

/** Creates a group and makes the user its owner. Returns the new id, or null with $error set. */
function shared_create_group(PDO $conn, int $userId, string $name, string $icon, ?string &$error = null): ?int
{
    $cnt = $conn->prepare('SELECT COUNT(*) FROM shared_members WHERE user_id = ?');
    $cnt->execute([$userId]);
    if ((int) $cnt->fetchColumn() >= SHARED_MAX_GROUPS_PER_USER) {
        $error = 'You are in the maximum number of groups (' . SHARED_MAX_GROUPS_PER_USER . ').';
        return null;
    }
    try {
        $conn->beginTransaction();
        $conn->prepare('INSERT INTO shared_groups (name, icon, owner_id, invite_code) VALUES (?,?,?,?)')
            ->execute([$name, shared_icon_key($icon), $userId, shared_new_code()]);
        $id = (int) $conn->lastInsertId();
        $conn->prepare('INSERT INTO shared_members (group_id, user_id, role) VALUES (?,?,\'owner\')')->execute([$id, $userId]);
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ExpenseFlow create group failed: ' . $e->getMessage());
        $error = 'Could not create the group. Please try again.';
        return null;
    }
}

/** Adds a user to a group. Returns 'joined', 'already', or 'full'. */
function shared_add_member(PDO $conn, int $groupId, int $userId): string
{
    $has = $conn->prepare('SELECT 1 FROM shared_members WHERE group_id = ? AND user_id = ?');
    $has->execute([$groupId, $userId]);
    if ($has->fetchColumn()) {
        return 'already';
    }
    $cnt = $conn->prepare('SELECT COUNT(*) FROM shared_members WHERE group_id = ?');
    $cnt->execute([$groupId]);
    if ((int) $cnt->fetchColumn() >= SHARED_MAX_MEMBERS) {
        return 'full';
    }
    $mine = $conn->prepare('SELECT COUNT(*) FROM shared_members WHERE user_id = ?');
    $mine->execute([$userId]);
    if ((int) $mine->fetchColumn() >= SHARED_MAX_GROUPS_PER_USER) {
        return 'full';
    }
    // INSERT IGNORE + the UNIQUE key: two taps on "Join" cannot add the user twice.
    $conn->prepare('INSERT IGNORE INTO shared_members (group_id, user_id, role) VALUES (?,?,\'member\')')->execute([$groupId, $userId]);
    return 'joined';
}

function shared_remove_member(PDO $conn, int $groupId, int $userId): void
{
    $conn->prepare('DELETE FROM shared_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $userId]);
}

/** Deletes a group completely. Later parts (expenses, settlements) add their cleanup here. */
function shared_delete_group(PDO $conn, int $groupId): void
{
    $conn->prepare('DELETE FROM shared_members WHERE group_id = ?')->execute([$groupId]);
    $conn->prepare('DELETE FROM shared_groups WHERE id = ?')->execute([$groupId]);
}

/**
 * Called when an account is deleted. Leaves every group; a group the user owned goes to the
 * longest-standing member, or is deleted when nobody else is in it.
 */
function shared_on_user_deleted(PDO $conn, int $userId): void
{
    shared_ensure_schema($conn);
    $owned = $conn->prepare('SELECT id FROM shared_groups WHERE owner_id = ?');
    $owned->execute([$userId]);
    foreach ($owned->fetchAll(PDO::FETCH_COLUMN) as $gid) {
        $next = $conn->prepare('SELECT user_id FROM shared_members WHERE group_id = ? AND user_id <> ? ORDER BY id ASC LIMIT 1');
        $next->execute([$gid, $userId]);
        $heir = $next->fetchColumn();
        if ($heir) {
            $conn->prepare('UPDATE shared_groups SET owner_id = ? WHERE id = ?')->execute([$heir, $gid]);
            $conn->prepare('UPDATE shared_members SET role = \'owner\' WHERE group_id = ? AND user_id = ?')->execute([$gid, $heir]);
        } else {
            shared_delete_group($conn, (int) $gid);
        }
    }
    $conn->prepare('DELETE FROM shared_members WHERE user_id = ?')->execute([$userId]);
}

function shared_invite_url(string $code): string
{
    return ef_url('shared/join.php?code=' . $code);
}
