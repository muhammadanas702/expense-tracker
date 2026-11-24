<?php
/**
 * Add a friend to a group from inside the app (Part 111a). A group member picks one of their friends; the friend sees an
 * invitation on Groups and Overview and joins only after accepting. Nothing happens to anyone's balance until they accept.
 */
const SHARED_INVITE_DAYS = 14;

function shared_invites_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `shared_member_invites` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `group_id` INT UNSIGNED NOT NULL,
              `inviter_id` INT UNSIGNED NOT NULL,
              `invitee_id` INT UNSIGNED NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_invite` (`group_id`,`invitee_id`),
              KEY `idx_invitee` (`invitee_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('shared invites schema: ' . $e->getMessage());
    }
}

/** Invitations waiting for $userId: [{id, group_id, group_name, group_icon, inviter}]. Old ones are ignored. */
function shared_invites_for(PDO $conn, int $userId): array
{
    shared_invites_ensure_schema($conn);
    $st = $conn->prepare(
        'SELECT i.id, i.group_id, g.name AS group_name, g.icon AS group_icon, u.name AS inviter
         FROM shared_member_invites i JOIN shared_groups g ON g.id = i.group_id JOIN users u ON u.id = i.inviter_id
         WHERE i.invitee_id = ? AND i.created_at > (NOW() - INTERVAL ' . (int) SHARED_INVITE_DAYS . ' DAY) ORDER BY i.id DESC LIMIT 20'
    );
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Friends of $userId who are not in the group yet: [{id, name, invited}] */
function shared_invitable_friends(PDO $conn, int $groupId, int $userId): array
{
    shared_invites_ensure_schema($conn);
    $ids = array_keys(friends_direct_ids($conn, $userId));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $st = $conn->prepare("SELECT u.id, u.name, (SELECT COUNT(*) FROM shared_member_invites i WHERE i.group_id = ? AND i.invitee_id = u.id) AS invited
        FROM users u WHERE u.id IN ($in) AND u.id NOT IN (SELECT user_id FROM shared_members WHERE group_id = ?) ORDER BY u.name");
    $st->execute([$groupId, $groupId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,msg:string} */
function shared_invite_friend(PDO $conn, int $groupId, int $me, int $friendId): array
{
    shared_invites_ensure_schema($conn);
    if (!shared_group_for($conn, $groupId, $me)) {
        return ['ok' => false, 'msg' => 'Group not found.'];
    }
    if (!isset(friends_direct_ids($conn, $me)[$friendId])) {
        return ['ok' => false, 'msg' => 'You can only add people who are your friends. Send a friend request first.'];
    }
    $in = $conn->prepare('SELECT 1 FROM shared_members WHERE group_id = ? AND user_id = ?');
    $in->execute([$groupId, $friendId]);
    if ($in->fetchColumn()) {
        return ['ok' => false, 'msg' => 'This friend is already in the group.'];
    }
    $key = rl_key((string) $me);
    if (rl_blocked($conn, 'group_invite', $key, 30, 3600)) {
        return ['ok' => false, 'msg' => 'Too many invitations just now. Please try again later.'];
    }
    rl_hit($conn, 'group_invite', $key);
    $conn->prepare('INSERT INTO shared_member_invites (group_id, inviter_id, invitee_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE inviter_id = VALUES(inviter_id), created_at = NOW()')->execute([$groupId, $me, $friendId]);
    return ['ok' => true, 'msg' => 'Invitation sent. ' . (shared_names($conn, [$friendId])[$friendId] ?? 'Your friend') . ' joins the group after accepting.'];
}

/** The invited person accepts or declines. @return array{ok:bool,msg:string} */
function shared_invite_respond(PDO $conn, int $me, int $inviteId, string $do): array
{
    shared_invites_ensure_schema($conn);
    $st = $conn->prepare('SELECT i.*, g.name FROM shared_member_invites i JOIN shared_groups g ON g.id = i.group_id WHERE i.id = ? AND i.invitee_id = ?');
    $st->execute([$inviteId, $me]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) {
        return ['ok' => false, 'msg' => 'That invitation is no longer available.'];
    }
    $conn->prepare('DELETE FROM shared_member_invites WHERE id = ?')->execute([$inviteId]);
    if ($do !== 'accept') {
        return ['ok' => true, 'msg' => 'Invitation declined.'];
    }
    $res = shared_add_member($conn, (int) $i['group_id'], $me);
    if ($res === 'full') {
        return ['ok' => false, 'msg' => 'This group is full, or you are in the maximum number of groups.'];
    }
    if ($res === 'joined') {
        $others = array_map(fn($m) => (int) $m['id'], shared_members_of($conn, (int) $i['group_id']));
        shared_emit($conn, (int) $i['group_id'], $me, 'member_join', [], $others);
    }
    return ['ok' => true, 'msg' => t('You joined %s.', $i['name']), 'group' => (int) $i['group_id']];
}

/** Card with pending invitations for the top of Groups and Overview. */
function shared_invites_html(PDO $conn, int $userId, string $actionUrl, string $csrfField): string
{
    $list = shared_invites_for($conn, $userId);
    if (!$list) {
        return '';
    }
    $h = '<section class="g card sec-panel sec-orange" id="group-invites"><div class="sec-head"><span class="sec-ic">' . ef_icon('users') . '</span><span class="sec-tt"><b>Group invitations</b><small class="sec-meta">' . count($list) . ' waiting for you</small></span></div><div class="sec-body">';
    foreach ($list as $i) {
        $h .= '<div class="row"><span>' . e($i['group_name']) . '<small>' . e($i['inviter']) . ' invited you</small></span>'
            . '<form method="post" action="' . e($actionUrl) . '" class="inl">' . $csrfField . '<input type="hidden" name="id" value="' . (int) $i['id'] . '">'
            . '<button class="btn sm" name="do" value="invite_accept">Join</button> <button class="btn sm o" name="do" value="invite_decline">Decline</button></form></div>';
    }
    return $h . '</div></section>';
}
