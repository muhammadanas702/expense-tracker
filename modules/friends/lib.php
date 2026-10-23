<?php
/**
 * Friends: anyone who is in at least one group with you, plus people you added directly
 * (a request that the other person accepted). Balances come from the same person-by-person
 * debts the groups show; only the direct friend links are stored (table friend_links).
 */

function friends_ensure_schema(PDO $conn): void
{
    if (function_exists('ef_schema_ok') && ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `friend_links` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `requester_id` INT UNSIGNED NOT NULL,
              `addressee_id` INT UNSIGNED NOT NULL,
              `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_pair` (`requester_id`,`addressee_id`),
              KEY `idx_addressee` (`addressee_id`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if (function_exists('ef_schema_mark')) {
            ef_schema_mark(__FUNCTION__, __FILE__);
        }
    } catch (Throwable $e) {
        error_log('friends schema: ' . $e->getMessage());
    }
}

/** @return array<int,int> friend user id => link id, for accepted direct links */
function friends_direct_ids(PDO $conn, int $userId): array
{
    friends_ensure_schema($conn);
    $st = $conn->prepare("SELECT IF(requester_id = ?, addressee_id, requester_id) AS fid, id FROM friend_links WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)");
    $st->execute([$userId, $userId, $userId]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id', 'fid');
}

/** @return array{in:array,out:array} pending requests sent to you / sent by you */
function friends_pending(PDO $conn, int $userId): array
{
    friends_ensure_schema($conn);
    $in = $conn->prepare("SELECT l.id, u.name FROM friend_links l JOIN users u ON u.id = l.requester_id WHERE l.addressee_id = ? AND l.status = 'pending' ORDER BY l.id DESC");
    $in->execute([$userId]);
    $out = $conn->prepare("SELECT l.id, u.name FROM friend_links l JOIN users u ON u.id = l.addressee_id WHERE l.requester_id = ? AND l.status = 'pending' ORDER BY l.id DESC");
    $out->execute([$userId]);
    return ['in' => $in->fetchAll(PDO::FETCH_ASSOC), 'out' => $out->fetchAll(PDO::FETCH_ASSOC)];
}

/**
 * Send a friend request by e-mail. The reply never reveals whether an address has an account.
 * @return array{ok:bool,msg:string}
 */
function friends_request(PDO $conn, int $me, string $email): array
{
    friends_ensure_schema($conn);
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        return ['ok' => false, 'msg' => 'Please enter a valid email address.'];
    }
    $key = rl_key((string) $me);
    if (rl_blocked($conn, 'friend_req', $key, 15, 3600)) {
        return ['ok' => false, 'msg' => 'Too many requests just now. Please try again later.'];
    }
    rl_hit($conn, 'friend_req', $key);
    $generic = ['ok' => true, 'msg' => 'Done. If that email has an ExpenseFlow account, the person will see your request.'];
    $st = $conn->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $other = $st->fetch(PDO::FETCH_ASSOC);
    if (!$other) {
        return $generic;
    }
    $oid = (int) $other['id'];
    if ($oid === $me) {
        return ['ok' => false, 'msg' => 'That is your own email address.'];
    }
    $ex = $conn->prepare('SELECT id, requester_id, status FROM friend_links WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1');
    $ex->execute([$me, $oid, $oid, $me]);
    $row = $ex->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['status'] === 'accepted') {
            return ['ok' => true, 'msg' => 'You are already friends.'];
        }
        if ((int) $row['requester_id'] === $oid) { // they already asked you: just accept
            $conn->prepare("UPDATE friend_links SET status = 'accepted' WHERE id = ?")->execute([$row['id']]);
            return ['ok' => true, 'msg' => 'You are now friends with ' . $other['name'] . '.'];
        }
        return $generic; // already sent
    }
    $conn->prepare("INSERT INTO friend_links (requester_id, addressee_id, status) VALUES (?, ?, 'pending')")->execute([$me, $oid]);
    $me_row = $conn->prepare('SELECT name FROM users WHERE id = ?');
    $me_row->execute([$me]);
    if (function_exists('sendFriendRequestMail')) {
        @sendFriendRequestMail($other['email'], $other['name'], (string) $me_row->fetchColumn());
    }
    return $generic;
}

/** accept / decline (addressee) or cancel / remove (either side). */
function friends_respond(PDO $conn, int $me, int $linkId, string $do): bool
{
    friends_ensure_schema($conn);
    $st = $conn->prepare('SELECT * FROM friend_links WHERE id = ? AND (requester_id = ? OR addressee_id = ?)');
    $st->execute([$linkId, $me, $me]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) {
        return false;
    }
    if ($do === 'accept' && $l['status'] === 'pending' && (int) $l['addressee_id'] === $me) {
        $conn->prepare("UPDATE friend_links SET status = 'accepted' WHERE id = ?")->execute([$linkId]);
        return true;
    }
    if (in_array($do, ['decline', 'cancel', 'remove'], true)) {
        $conn->prepare('DELETE FROM friend_links WHERE id = ?')->execute([$linkId]);
        return true;
    }
    return false;
}

function friends_on_user_deleted(PDO $conn, int $userId): void
{
    friends_ensure_schema($conn);
    $conn->prepare('DELETE FROM friend_links WHERE requester_id = ? OR addressee_id = ?')->execute([$userId, $userId]);
}

/**
 * @return array<int,array{id:int,name:string,groups:array<int,string>,owed:array<string,int>,owe:array<string,int>,todo:array<int,array>}>
 *         keyed by friend user id, sorted: open balances first, then by name
 */
function friends_list(PDO $conn, int $userId, ?array $groups = null): array
{
    $groups = $groups ?? shared_groups_of($conn, $userId);
    $gids = array_map(fn($g) => (int) $g['id'], $groups);
    $gnames = array_column($groups, 'name', 'id');
    $friends = [];
    if ($gids) {
        $in = implode(',', $gids); // integers only
        $stmt = $conn->prepare("SELECT m.group_id, u.id, u.name FROM shared_members m JOIN users u ON u.id = m.user_id WHERE m.group_id IN ($in) AND m.user_id <> ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $friends[$id] ??= ['id' => $id, 'name' => $r['name'], 'groups' => [], 'owed' => [], 'owe' => [], 'todo' => [], 'direct' => false, 'link_id' => 0];
            $friends[$id]['groups'][(int) $r['group_id']] = $gnames[(int) $r['group_id']] ?? 'Group';
        }
    }
    $direct = friends_direct_ids($conn, $userId);
    if ($direct) {
        $in2 = implode(',', array_map('intval', array_keys($direct)));
        foreach ($conn->query("SELECT id, name FROM users WHERE id IN ($in2)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $friends[$id] ??= ['id' => $id, 'name' => $r['name'], 'groups' => [], 'owed' => [], 'owe' => [], 'todo' => [], 'direct' => true, 'link_id' => 0];
            $friends[$id]['direct'] = true;
            $friends[$id]['link_id'] = (int) $direct[$id];
        }
    }
    if (!$friends) {
        return [];
    }
    foreach ($gids ? shared_user_summary($conn, $gids, $userId)['todo'] : [] as $p) {
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
