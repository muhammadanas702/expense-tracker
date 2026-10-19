<?php
/**
 * Staff view of shared groups. Read-only counts (no expense details or names of members) plus delete.
 * Deleting uses the Shared module's own shared_delete_group() so every module cleans up after itself.
 */
require_once __DIR__ . '/../shared/lib.php';

const GROUPSADMIN_PER_PAGE = 25;

/** [rows, total]. sort: new | old | big (most expenses) | name */
function groupsadmin_list(PDO $conn, string $q, string $sort, int $page): array
{
    shared_ensure_all_schemas($conn);
    $where = '';
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where = 'WHERE (g.name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        $params = [$like, $like, $like];
    }
    $order = ['new' => 'g.id DESC', 'old' => 'g.id ASC', 'big' => 'expenses DESC, g.id DESC', 'name' => 'g.name ASC, g.id DESC'][$sort] ?? 'g.id DESC';
    $t = $conn->prepare("SELECT COUNT(*) FROM shared_groups g LEFT JOIN users u ON u.id = g.owner_id $where");
    $t->execute($params);
    $total = (int) $t->fetchColumn();
    $off = max(0, ($page - 1) * GROUPSADMIN_PER_PAGE);
    $s = $conn->prepare(
        "SELECT g.id, g.name, g.created_at, u.name AS owner_name,
                (SELECT COUNT(*) FROM shared_members m WHERE m.group_id = g.id) AS members,
                (SELECT COUNT(*) FROM shared_expenses x WHERE x.group_id = g.id) AS expenses
         FROM shared_groups g LEFT JOIN users u ON u.id = g.owner_id $where
         ORDER BY $order LIMIT " . GROUPSADMIN_PER_PAGE . " OFFSET $off"
    );
    $s->execute($params);
    return [$s->fetchAll(PDO::FETCH_ASSOC), $total];
}

function groupsadmin_totals(PDO $conn): array
{
    shared_ensure_all_schemas($conn);
    return [
        'groups' => (int) $conn->query('SELECT COUNT(*) FROM shared_groups')->fetchColumn(),
        'expenses' => (int) $conn->query('SELECT COUNT(*) FROM shared_expenses')->fetchColumn(),
    ];
}

function groupsadmin_get(PDO $conn, int $id): ?array
{
    $s = $conn->prepare('SELECT id, name FROM shared_groups WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Deletes a group with everything in it (own transaction; schemas are made first). */
function groupsadmin_delete(PDO $conn, int $id): void
{
    shared_ensure_all_schemas($conn);
    $conn->beginTransaction();
    try {
        shared_delete_group($conn, $id);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
