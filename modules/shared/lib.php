<?php
require_once __DIR__ . '/../../includes/schema.php';
/**
 * Shared module helpers: groups, members, invite links.
 * This module only touches the shared_* tables (and reads user names from users).
 * Later parts add expenses/settlements in their own tables and hook into
 * shared_delete_group() / shared_on_user_deleted() for cleanup.
 */
require_once __DIR__ . '/split.php';
require_once __DIR__ . '/balance.php';
if (is_file(__DIR__ . '/../activity/lib.php')) {
    require_once __DIR__ . '/../activity/lib.php'; // optional: feed + notifications
}
foreach (['comments', 'receipts', 'support', 'announce'] as $ef_opt) {
    if (is_file(__DIR__ . '/../' . $ef_opt . '/lib.php')) {
        require_once __DIR__ . '/../' . $ef_opt . '/lib.php'; // optional modules, each with its own tables
    }
}
unset($ef_opt);
require_once __DIR__ . '/../personal/lib.php';
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
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_expenses` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `title` VARCHAR(100) NOT NULL,
              `amount_minor` BIGINT UNSIGNED NOT NULL,
              `currency` VARCHAR(3) NOT NULL DEFAULT 'PKR',
              `paid_by` INT UNSIGNED NOT NULL,
              `created_by` INT UNSIGNED NOT NULL,
              `split_type` VARCHAR(8) NOT NULL DEFAULT 'equal',
              `expense_date` DATE NOT NULL,
              `note` VARCHAR(255) NULL DEFAULT NULL,
              `personal_expense_id` INT UNSIGNED NULL DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_shared_expenses_group` (`group_id`, `expense_date`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_splits` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `expense_id` INT UNSIGNED NOT NULL,
              `user_id` INT UNSIGNED NOT NULL,
              `share_minor` BIGINT UNSIGNED NOT NULL,
              `weight` DECIMAL(12,2) NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_shared_splits` (`expense_id`, `user_id`),
              KEY `idx_shared_splits_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_settlements` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `from_user` INT UNSIGNED NOT NULL,
              `to_user` INT UNSIGNED NOT NULL,
              `amount_minor` BIGINT UNSIGNED NOT NULL,
              `currency` VARCHAR(3) NOT NULL DEFAULT 'PKR',
              `settled_on` DATE NOT NULL,
              `note` VARCHAR(255) NULL DEFAULT NULL,
              `created_by` INT UNSIGNED NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_shared_settlements_group` (`group_id`, `settled_on`, `id`)
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

/** Deletes a group completely (expenses and splits included). Later parts add their cleanup here. */
function shared_delete_group(PDO $conn, int $groupId): void
{
    foreach (['receipts_on_group_deleted', 'comments_on_group_deleted'] as $hook) {
        if (function_exists($hook)) {
            $hook($conn, $groupId);
        }
    }
    $conn->prepare('DELETE FROM shared_splits WHERE expense_id IN (SELECT id FROM shared_expenses WHERE group_id = ?)')->execute([$groupId]);
    $conn->prepare('DELETE FROM shared_expenses WHERE group_id = ?')->execute([$groupId]);
    $conn->prepare('DELETE FROM shared_settlements WHERE group_id = ?')->execute([$groupId]);
    if (function_exists('activity_on_group_deleted')) {
        activity_on_group_deleted($conn, $groupId);
    }
    $conn->prepare('DELETE FROM shared_members WHERE group_id = ?')->execute([$groupId]);
    $conn->prepare('DELETE FROM shared_groups WHERE id = ?')->execute([$groupId]);
}

/**
 * Makes sure Shared and every optional module table exists. Call it BEFORE beginTransaction():
 * CREATE TABLE commits an open transaction in MySQL.
 */
function shared_ensure_all_schemas(PDO $conn): void
{
    shared_ensure_schema($conn);
    foreach (['activity_ensure_schema', 'comments_ensure_schema', 'receipts_ensure_schema', 'support_ensure_schema', 'announce_ensure_schema'] as $fn) {
        if (function_exists($fn)) {
            $fn($conn);
        }
    }
}

/**
 * Called when an account is deleted. Leaves every group; a group the user owned goes to the
 * longest-standing member, or is deleted when nobody else is in it.
 */
function shared_on_user_deleted(PDO $conn, int $userId): void
{
    shared_ensure_schema($conn);
    // Debts to or from the deleted account can never be settled, so they are written off
    // as payments (the other person's balance with this account becomes zero).
    $mine = $conn->prepare('SELECT group_id FROM shared_members WHERE user_id = ?');
    $mine->execute([$userId]);
    $gids = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));
    foreach (shared_group_pairs($conn, $gids) as $g => $byCur) {
        foreach ($byCur as $cur => $list) {
            foreach ($list as $p) {
                if ($p['from'] === $userId || $p['to'] === $userId) {
                    $other = $p['from'] === $userId ? $p['to'] : $p['from'];
                    $conn->prepare('INSERT INTO shared_settlements (group_id,from_user,to_user,amount_minor,currency,settled_on,note,created_by) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$g, $p['from'], $p['to'], $p['amount'], $cur, date('Y-m-d'), 'Written off: account deleted', $other]);
                }
            }
        }
    }
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
    if (function_exists('activity_on_user_deleted')) {
        activity_on_user_deleted($conn, $userId);
    }
    if (function_exists('support_on_user_deleted')) {
        support_on_user_deleted($conn, $userId);
    }
    if (function_exists('announce_on_user_deleted')) {
        announce_on_user_deleted($conn, $userId);
    }
    if (function_exists('comments_on_user_deleted')) {
        comments_on_user_deleted($conn, $userId);
    }
}

function shared_invite_url(string $code): string
{
    return ef_url('shared/join.php?code=' . $code);
}


// ---------------------------------------------------------------- expenses (Part 6)

const SHARED_MAX_EXPENSES_PER_HOUR = 60;

/** One expense of a group (null if it is not in that group). */
function shared_expense_get(PDO $conn, int $expenseId, int $groupId): ?array
{
    $q = $conn->prepare('SELECT * FROM shared_expenses WHERE id = ? AND group_id = ?');
    $q->execute([$expenseId, $groupId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Splits of one expense: user_id => ['share_minor'=>int,'weight'=>?string]. */
function shared_splits_of(PDO $conn, int $expenseId): array
{
    $q = $conn->prepare('SELECT user_id, share_minor, weight FROM shared_splits WHERE expense_id = ? ORDER BY user_id');
    $q->execute([$expenseId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['user_id']] = ['share_minor' => (int) $r['share_minor'], 'weight' => $r['weight']];
    }
    return $out;
}

/**
 * Latest expenses of a group with the payer name and the signed-in user's share.
 * A user who deleted their account shows as NULL name (page prints "Former member").
 */
function shared_expenses_of(PDO $conn, int $groupId, int $userId, int $limit = 100): array
{
    $q = $conn->prepare(
        'SELECT e.*, u.name AS payer_name, s.share_minor AS my_share
         FROM shared_expenses e
         LEFT JOIN users u ON u.id = e.paid_by
         LEFT JOIN shared_splits s ON s.expense_id = e.id AND s.user_id = ?
         WHERE e.group_id = ? ORDER BY e.expense_date DESC, e.id DESC LIMIT ' . (int) $limit
    );
    $q->execute([$userId, $groupId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Totals per currency for a group: [['currency'=>'PKR','total'=>12345], ...] (minor units). */
function shared_group_totals(PDO $conn, int $groupId): array
{
    $q = $conn->prepare('SELECT currency, SUM(amount_minor) AS total FROM shared_expenses WHERE group_id = ? GROUP BY currency ORDER BY total DESC');
    $q->execute([$groupId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function shared_money(int $minor, string $currency): string
{
    return e($currency) . ' ' . number_format($minor / 100, 2);
}

/**
 * Creates the Personal expense that mirrors one member's share. Returns the new expense id.
 * Uses the same columns and the "monthly base currency" rule as the Personal add page.
 */
function shared_personal_copy(PDO $conn, int $userId, string $title, int $shareMinor, string $currency, string $category, string $date, string $groupName): int
{
    $amount = $shareMinor / 100;
    $pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
    $when = $date === date('Y-m-d') ? date('Y-m-d H:i:s') : $date . ' 12:00:00';
    $ym = substr($date, 0, 7);
    $has = $conn->prepare('SELECT 1 FROM user_monthly_currency WHERE user_id = ? AND `year_month` = ?');
    $has->execute([$userId, $ym]);
    if (!$has->fetchColumn()) {
        $conn->prepare('INSERT INTO user_monthly_currency (user_id, `year_month`, base_currency) VALUES (?,?,?)')->execute([$userId, $ym, $currency]);
    }
    $t = mb_substr($title, 0, 100);
    $conn->prepare('INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date,payment_method,note) VALUES (?,?,?,?,?,?,?,NULL,?)')
        ->execute([$userId, $t, $amount, $currency, $pkr, $category, $when, mb_substr('My share · ' . $groupName, 0, 255)]);
    return (int) $conn->lastInsertId();
}

/** Removes the linked Personal copy, only if it still belongs to $userId. */
function shared_personal_copy_delete(PDO $conn, ?int $personalId, int $userId): void
{
    if ($personalId) {
        $conn->prepare('DELETE FROM expenses WHERE id = ? AND user_id = ?')->execute([$personalId, $userId]);
    }
}

/**
 * Saves a new expense (or replaces an existing one) with its splits, in one transaction.
 * $d: title, amount_minor, currency, paid_by, split_type, expense_date, note, shares[uid=>minor], weights[uid=>?float]
 * $personal: null (no change) | false (remove my copy) | string category (create/update my copy; only for the creator).
 * Returns the expense id.
 */
function shared_expense_save(PDO $conn, array $group, int $userId, array $d, $personal, ?array $existing = null): int
{
    $conn->beginTransaction();
    try {
        if ($existing) {
            $id = (int) $existing['id'];
            $conn->prepare('UPDATE shared_expenses SET title=?, amount_minor=?, currency=?, paid_by=?, split_type=?, expense_date=?, note=? WHERE id=? AND group_id=?')
                ->execute([$d['title'], $d['amount_minor'], $d['currency'], $d['paid_by'], $d['split_type'], $d['expense_date'], $d['note'], $id, $group['id']]);
            $conn->prepare('DELETE FROM shared_splits WHERE expense_id = ?')->execute([$id]);
            $personalId = $existing['personal_expense_id'] !== null ? (int) $existing['personal_expense_id'] : null;
        } else {
            $conn->prepare('INSERT INTO shared_expenses (group_id,title,amount_minor,currency,paid_by,created_by,split_type,expense_date,note) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$group['id'], $d['title'], $d['amount_minor'], $d['currency'], $d['paid_by'], $userId, $d['split_type'], $d['expense_date'], $d['note']]);
            $id = (int) $conn->lastInsertId();
            $personalId = null;
        }
        $ins = $conn->prepare('INSERT INTO shared_splits (expense_id,user_id,share_minor,weight) VALUES (?,?,?,?)');
        foreach ($d['shares'] as $uid => $minor) {
            $ins->execute([$id, $uid, $minor, $d['weights'][$uid] ?? null]);
        }
        if ($personal !== null) {
            $mine = $d['shares'][$userId] ?? 0;
            if ($personal === false || $mine < 1) {
                shared_personal_copy_delete($conn, $personalId, $userId);
                $conn->prepare('UPDATE shared_expenses SET personal_expense_id = NULL WHERE id = ?')->execute([$id]);
            } else {
                shared_personal_copy_delete($conn, $personalId, $userId); // replace: keeps one copy only
                $newId = shared_personal_copy($conn, $userId, $d['title'], $mine, $d['currency'], $personal, $d['expense_date'], $group['name']);
                $conn->prepare('UPDATE shared_expenses SET personal_expense_id = ? WHERE id = ?')->execute([$newId, $id]);
            }
        }
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function shared_expense_delete(PDO $conn, array $expense): void
{
    foreach (['receipts_on_expense_deleted', 'comments_on_expense_deleted'] as $hook) {
        if (function_exists($hook)) {
            $hook($conn, (int) $expense['id']);
        }
    }
    $conn->prepare('DELETE FROM shared_splits WHERE expense_id = ?')->execute([$expense['id']]);
    $conn->prepare('DELETE FROM shared_expenses WHERE id = ?')->execute([$expense['id']]);
    // The creator's Personal copy was made for this expense, so it goes too.
    shared_personal_copy_delete($conn, $expense['personal_expense_id'] !== null ? (int) $expense['personal_expense_id'] : null, (int) $expense['created_by']);
}

/** Who may change an expense: the person who added it, or the group owner. */
function shared_can_edit(array $expense, array $group, int $userId): bool
{
    return (int) $expense['created_by'] === $userId || $group['my_role'] === 'owner';
}


// ---------------------------------------------------------------- balances and settle up (Part 7)

/**
 * Money parts of every person, per group and currency (minor units):
 *   paid = what they paid, share = their own shares, sent / recv = settle-up payments.
 * @return array<int,array<string,array<int,array{paid:int,share:int,sent:int,recv:int}>>>  [group_id][currency][user_id]
 */
function shared_group_parts(PDO $conn, array $groupIds): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    if (!$groupIds) {
        return [];
    }
    $in = implode(',', $groupIds); // integers only
    $parts = [];
    $add = function (int $g, string $cur, int $uid, string $k, int $v) use (&$parts) {
        $parts[$g][$cur][$uid] = $parts[$g][$cur][$uid] ?? ['paid' => 0, 'share' => 0, 'sent' => 0, 'recv' => 0];
        $parts[$g][$cur][$uid][$k] += $v;
    };
    foreach ($conn->query("SELECT group_id, currency, paid_by, SUM(amount_minor) AS t FROM shared_expenses WHERE group_id IN ($in) GROUP BY group_id, currency, paid_by")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add((int) $r['group_id'], $r['currency'], (int) $r['paid_by'], 'paid', (int) $r['t']);
    }
    foreach ($conn->query("SELECT e.group_id, e.currency, s.user_id, SUM(s.share_minor) AS t FROM shared_splits s JOIN shared_expenses e ON e.id = s.expense_id WHERE e.group_id IN ($in) GROUP BY e.group_id, e.currency, s.user_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add((int) $r['group_id'], $r['currency'], (int) $r['user_id'], 'share', (int) $r['t']);
    }
    foreach ($conn->query("SELECT group_id, currency, from_user, to_user, SUM(amount_minor) AS t FROM shared_settlements WHERE group_id IN ($in) GROUP BY group_id, currency, from_user, to_user")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add((int) $r['group_id'], $r['currency'], (int) $r['from_user'], 'sent', (int) $r['t']);
        $add((int) $r['group_id'], $r['currency'], (int) $r['to_user'], 'recv', (int) $r['t']);
    }
    return $parts;
}

/**
 * Net balance of every person, per group and currency: paid - shares + payments sent - payments received.
 * Positive = is owed, negative = owes. Every currency of a group sums to zero.
 * @return array<int,array<string,array<int,int>>>  [group_id][currency][user_id] => minor units
 */
function shared_group_nets(PDO $conn, array $groupIds): array
{
    $net = [];
    foreach (shared_group_parts($conn, $groupIds) as $g => $byCur) {
        foreach ($byCur as $cur => $byUser) {
            foreach ($byUser as $uid => $p) {
                $net[$g][$cur][$uid] = $p['paid'] - $p['share'] + $p['sent'] - $p['recv'];
            }
        }
    }
    return $net;
}

/**
 * Who owes whom, person to person, per group and currency. Each expense makes every
 * participant owe the payer their share; payments reduce that debt. Two people who owe each
 * other only cancel each other out; debts with different people never mix.
 * @return array<int,array<string,array<int,array{from:int,to:int,amount:int}>>>  [group_id][currency][] => payment
 */
function shared_group_pairs(PDO $conn, array $groupIds): array
{
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
    if (!$groupIds) {
        return [];
    }
    $in = implode(',', $groupIds); // integers only
    $debt = []; // [g][cur][from][to]
    foreach ($conn->query("SELECT e.group_id, e.currency, s.user_id AS f, e.paid_by AS t, SUM(s.share_minor) AS v FROM shared_splits s JOIN shared_expenses e ON e.id = s.expense_id WHERE e.group_id IN ($in) AND s.user_id <> e.paid_by GROUP BY e.group_id, e.currency, s.user_id, e.paid_by")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = (int) $r['group_id'];
        $debt[$g][$r['currency']][(int) $r['f']][(int) $r['t']] = ($debt[$g][$r['currency']][(int) $r['f']][(int) $r['t']] ?? 0) + (int) $r['v'];
    }
    foreach ($conn->query("SELECT group_id, currency, from_user AS f, to_user AS t, SUM(amount_minor) AS v FROM shared_settlements WHERE group_id IN ($in) GROUP BY group_id, currency, from_user, to_user")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = (int) $r['group_id'];
        $debt[$g][$r['currency']][(int) $r['f']][(int) $r['t']] = ($debt[$g][$r['currency']][(int) $r['f']][(int) $r['t']] ?? 0) - (int) $r['v'];
    }
    $out = [];
    foreach ($debt as $g => $byCur) {
        foreach ($byCur as $cur => $d) {
            if ($list = shared_pair_debts($d)) {
                $out[$g][$cur] = $list;
            }
        }
    }
    return $out;
}

/**
 * What the user owes and is owed, person by person, across the given groups.
 * @return array{owed:array<string,int>,owe:array<string,int>,todo:array<int,array>}
 */
function shared_user_summary(PDO $conn, array $groupIds, int $userId): array
{
    $out = ['owed' => [], 'owe' => [], 'todo' => []];
    foreach (shared_group_pairs($conn, $groupIds) as $g => $byCur) {
        foreach ($byCur as $cur => $list) {
            foreach ($list as $p) {
                if ($p['to'] === $userId) {
                    $out['owed'][$cur] = ($out['owed'][$cur] ?? 0) + $p['amount'];
                } elseif ($p['from'] === $userId) {
                    $out['owe'][$cur] = ($out['owe'][$cur] ?? 0) + $p['amount'];
                } else {
                    continue;
                }
                $p['group'] = $g;
                $p['currency'] = $cur;
                $out['todo'][] = $p;
            }
        }
    }
    return $out;
}

/** True when the user still owes or is owed something in this group. */
function shared_has_open_balance(PDO $conn, int $groupId, int $userId): bool
{
    foreach (shared_group_pairs($conn, [$groupId])[$groupId] ?? [] as $list) {
        foreach ($list as $p) {
            if ($p['from'] === $userId || $p['to'] === $userId) {
                return true;
            }
        }
    }
    return false;
}

/** Latest payments of a group. */
function shared_settlements_of(PDO $conn, int $groupId, int $limit = 30): array
{
    $q = $conn->prepare(
        'SELECT s.*, f.name AS from_name, t.name AS to_name FROM shared_settlements s
         LEFT JOIN users f ON f.id = s.from_user LEFT JOIN users t ON t.id = s.to_user
         WHERE s.group_id = ? ORDER BY s.settled_on DESC, s.id DESC LIMIT ' . (int) $limit
    );
    $q->execute([$groupId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function shared_settlement_get(PDO $conn, int $id, int $groupId): ?array
{
    $q = $conn->prepare('SELECT * FROM shared_settlements WHERE id = ? AND group_id = ?');
    $q->execute([$id, $groupId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Names for a set of user ids; deleted accounts become "Former member". */
function shared_names(PDO $conn, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    if ($ids) {
        foreach ($conn->query('SELECT id, name FROM users WHERE id IN (' . implode(',', $ids) . ')') as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
    }
    return $out;
}


// ---------------------------------------------------------------- double-submit protection

/** A one-time form token: a second submit of the same form (double tap, refresh) is ignored. */
function shared_token_new(): string
{
    $t = bin2hex(random_bytes(12));
    $list = $_SESSION['shared_tokens'] ?? [];
    $list[$t] = time();
    arsort($list);
    $_SESSION['shared_tokens'] = array_slice($list, 0, 30, true);
    return $t;
}

function shared_token_valid($t): bool
{
    return is_string($t) && isset($_SESSION['shared_tokens'][$t]);
}

function shared_token_use($t): void
{
    unset($_SESSION['shared_tokens'][$t]);
}


// ---------------------------------------------------------------- activity hook (Part 8a)

/** Records an activity event when the Activity module is installed. Never throws. */
function shared_emit(PDO $conn, int $groupId, int $actorId, string $kind, array $d = [], array $notify = []): void
{
    if (function_exists('activity_record')) {
        activity_record($conn, $groupId, $actorId, $kind, $d, $notify);
    }
}
