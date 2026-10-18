<?php
/**
 * Friends: derived from Shared groups. A "friend" is anyone who is in at least one group with
 * you. Nothing is stored; balances come from the same person-by-person debts the groups show.
 */

/**
 * @return array<int,array{id:int,name:string,groups:array<int,string>,owed:array<string,int>,owe:array<string,int>,todo:array<int,array>}>
 *         keyed by friend user id, sorted: open balances first, then by name
 */
function friends_list(PDO $conn, int $userId, ?array $groups = null): array
{
    $groups = $groups ?? shared_groups_of($conn, $userId);
    $gids = array_map(fn($g) => (int) $g['id'], $groups);
    if (!$gids) {
        return [];
    }
    $gnames = array_column($groups, 'name', 'id');
    $in = implode(',', $gids); // integers only
    $friends = [];
    $stmt = $conn->prepare("SELECT m.group_id, u.id, u.name FROM shared_members m JOIN users u ON u.id = m.user_id WHERE m.group_id IN ($in) AND m.user_id <> ?");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['id'];
        $friends[$id] ??= ['id' => $id, 'name' => $r['name'], 'groups' => [], 'owed' => [], 'owe' => [], 'todo' => []];
        $friends[$id]['groups'][(int) $r['group_id']] = $gnames[(int) $r['group_id']] ?? 'Group';
    }
    foreach (shared_user_summary($conn, $gids, $userId)['todo'] as $p) {
        $other = $p['from'] === $userId ? $p['to'] : $p['from'];
        if (!isset($friends[$other])) {
            continue; // a former member: not a friend any more
        }
        $key = $p['from'] === $userId ? 'owe' : 'owed';
        $friends[$other][$key][$p['currency']] = ($friends[$other][$key][$p['currency']] ?? 0) + $p['amount'];
        $friends[$other]['todo'][] = $p;
    }
    uasort($friends, function ($a, $b) {
        $ao = $a['todo'] ? 0 : 1;
        $bo = $b['todo'] ? 0 : 1;
        return $ao <=> $bo ?: strcasecmp($a['name'], $b['name']);
    });
    return $friends;
}

/** Net per currency from the user's side: positive = they owe you. */
function friends_net(array $f): array
{
    $net = $f['owed'];
    foreach ($f['owe'] as $cur => $v) {
        $net[$cur] = ($net[$cur] ?? 0) - $v;
    }
    return array_filter($net);
}

/** One friend, or null when this person does not share a group with the user. */
function friends_get(PDO $conn, int $userId, int $friendId): ?array
{
    $all = friends_list($conn, $userId);
    return $all[$friendId] ?? null;
}

/** Payments between exactly these two people inside the groups they share, newest first. */
function friends_settlements(PDO $conn, int $userId, int $friendId, array $groupIds, int $limit = 20): array
{
    $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
    if (!$groupIds) {
        return [];
    }
    $in = implode(',', $groupIds);
    $stmt = $conn->prepare("SELECT s.id, s.group_id, s.from_user, s.to_user, s.amount_minor, s.currency, s.settled_on, g.name AS group_name
        FROM shared_settlements s JOIN shared_groups g ON g.id = s.group_id
        WHERE s.group_id IN ($in) AND ((s.from_user = ? AND s.to_user = ?) OR (s.from_user = ? AND s.to_user = ?))
        ORDER BY s.settled_on DESC, s.id DESC LIMIT " . (int) $limit);
    $stmt->execute([$userId, $friendId, $friendId, $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
