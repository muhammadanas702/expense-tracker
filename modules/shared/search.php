<?php
require_once __DIR__ . '/lib.php';
/**
 * Search inside the Shared side. Read-only. Only ever reads groups the person belongs to.
 * Filters: q (title, note, who paid, group name), group (id), type (all|expense|settlement), from, to (dates).
 */
const SHARED_SEARCH_LIMIT = 100;

function shared_search_filters(array $in): array
{
    $f = ['q' => '', 'group' => 0, 'type' => 'all', 'from' => '', 'to' => ''];
    $f['q'] = mb_substr(trim((string) ($in['q'] ?? '')), 0, 60);
    $f['group'] = max(0, (int) ($in['group'] ?? 0));
    $f['type'] = in_array($in['type'] ?? '', ['expense', 'settlement'], true) ? $in['type'] : 'all';
    foreach (['from', 'to'] as $k) {
        $d = (string) ($in[$k] ?? '');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        $f[$k] = $dt && $dt->format('Y-m-d') === $d ? $d : '';
    }
    return $f;
}

function shared_search_active(array $f): bool
{
    return $f['q'] !== '' || $f['group'] > 0 || $f['type'] !== 'all' || $f['from'] !== '' || $f['to'] !== '';
}

/** Returns ['rows' => [...], 'count' => n]. Rows: kind, id, group_id, group_name, title, amount_minor, currency, date, note, who. */
function shared_search_run(PDO $conn, int $userId, array $f): array
{
    $out = ['rows' => [], 'count' => 0];
    $groups = shared_groups_of($conn, $userId);
    $ids = array_map(fn($g) => (int) $g['id'], $groups);
    if ($f['group'] > 0) {
        $ids = in_array($f['group'], $ids, true) ? [$f['group']] : [];
    }
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $like = '%' . addcslashes($f['q'], '\\%_') . '%';
    $parts = [];
    $params = [];
    if ($f['type'] !== 'settlement') {
        $w = ["e.group_id IN ($in)"];
        $p = $ids;
        if ($f['q'] !== '') {
            $w[] = '(e.title LIKE ? OR e.note LIKE ? OR g.name LIKE ? OR pu.name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if ($f['from'] !== '') { $w[] = 'e.expense_date >= ?'; $p[] = $f['from']; }
        if ($f['to'] !== '') { $w[] = 'e.expense_date <= ?'; $p[] = $f['to']; }
        $parts[] = "SELECT 'expense' AS kind, e.id, e.group_id, g.name AS group_name, e.title, e.amount_minor, e.currency, e.expense_date AS d, e.note, pu.name AS who
                    FROM shared_expenses e JOIN shared_groups g ON g.id = e.group_id LEFT JOIN users pu ON pu.id = e.paid_by WHERE " . implode(' AND ', $w);
        $params = array_merge($params, $p);
    }
    if ($f['type'] !== 'expense') {
        $w = ["s.group_id IN ($in)"];
        $p = $ids;
        if ($f['q'] !== '') {
            $w[] = '(s.note LIKE ? OR g.name LIKE ? OR fu.name LIKE ? OR tu.name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if ($f['from'] !== '') { $w[] = 's.settled_on >= ?'; $p[] = $f['from']; }
        if ($f['to'] !== '') { $w[] = 's.settled_on <= ?'; $p[] = $f['to']; }
        $parts[] = "SELECT 'settlement' AS kind, s.id, s.group_id, g.name AS group_name, CONCAT(COALESCE(fu.name,'Former member'), ' paid ', COALESCE(tu.name,'Former member')) AS title, s.amount_minor, s.currency, s.settled_on AS d, s.note, fu.name AS who
                    FROM shared_settlements s JOIN shared_groups g ON g.id = s.group_id LEFT JOIN users fu ON fu.id = s.from_user LEFT JOIN users tu ON tu.id = s.to_user WHERE " . implode(' AND ', $w);
        $params = array_merge($params, $p);
    }
    $union = implode(' UNION ALL ', $parts);
    $c = $conn->prepare("SELECT COUNT(*) FROM ($union) x");
    $c->execute($params);
    $out['count'] = (int) $c->fetchColumn();
    $q = $conn->prepare("$union ORDER BY d DESC, id DESC LIMIT " . SHARED_SEARCH_LIMIT);
    $q->execute($params);
    $out['rows'] = $q->fetchAll(PDO::FETCH_ASSOC);
    return $out;
}
