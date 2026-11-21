<?php
/** Part 111a: add a friend to a group from inside the app (needs the local test database). Run: php tests/group_friend_invite_test.php */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
require __DIR__ . '/../modules/shared/invites.php';
require __DIR__ . '/../modules/friends/lib.php';
shared_ensure_all_schemas($conn);
friends_ensure_schema($conn);
$fail = 0;
function ok($c, $m) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) { $fail++; } }
$ids = [];
foreach (['a', 'b', 'c', 'd'] as $n) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute(["Inv $n", "inv$n@t.local", 'x']);
    $ids[$n] = (int) $conn->lastInsertId();
}
[$a, $b, $c, $d] = array_values($ids);
$conn->prepare("INSERT INTO friend_links (requester_id,addressee_id,status) VALUES (?,?,'accepted')")->execute([$a, $b]);
$conn->prepare("INSERT INTO friend_links (requester_id,addressee_id,status) VALUES (?,?,'pending')")->execute([$a, $c]);
$gid = shared_create_group($conn, $a, 'Invite test', 'users');
$r = shared_invite_friend($conn, $gid, $a, $d);
ok(!$r['ok'], 'a stranger cannot be added');
$r = shared_invite_friend($conn, $gid, $a, $c);
ok(!$r['ok'], 'a pending friend request is not a friend yet');
ok(!shared_invite_friend($conn, $gid, $b, $a)['ok'], 'a non-member cannot invite');
$r = shared_invite_friend($conn, $gid, $a, $b);
ok($r['ok'], 'a friend can be invited');
ok(count(shared_members_of($conn, $gid)) === 1, 'not a member before accepting');
ok(count(shared_invites_for($conn, $b)) === 1, 'friend sees the invitation');
ok(count(shared_invites_for($conn, $a)) === 0, 'inviter sees no invitation');
$l = shared_invitable_friends($conn, $gid, $a);
ok(count($l) === 1 && (int) $l[0]['invited'] === 1, 'picker shows "Invited"');
shared_invite_friend($conn, $gid, $a, $b);
ok(count(shared_invites_for($conn, $b)) === 1, 'inviting twice keeps one invitation');
$iid = (int) shared_invites_for($conn, $b)[0]['id'];
ok(!shared_invite_respond($conn, $a, $iid, 'accept')['ok'], 'only the invited person can answer');
$r = shared_invite_respond($conn, $b, $iid, 'accept');
ok($r['ok'] && count(shared_members_of($conn, $gid)) === 2, 'accepting adds the friend to the group');
ok(shared_invitable_friends($conn, $gid, $a) === [], 'member no longer in the picker');
$g2 = shared_create_group($conn, $a, 'Second', 'users');
shared_invite_friend($conn, $g2, $a, $b);
$r = shared_invite_respond($conn, $b, (int) shared_invites_for($conn, $b)[0]['id'], 'decline');
ok($r['ok'] && !shared_group_for($conn, $g2, $b) && !shared_invites_for($conn, $b), 'declining adds nobody and clears it');
shared_invite_friend($conn, $g2, $a, $b);
shared_delete_group($conn, $g2);
ok(!shared_invites_for($conn, $b), 'deleting a group removes its invitations');
shared_delete_group($conn, $gid);
$conn->exec("DELETE FROM friend_links WHERE requester_id IN ($a,$b,$c,$d)");
$conn->exec("DELETE FROM users WHERE id IN ($a,$b,$c,$d)");
echo $fail ? "FAILED: $fail\n" : "group_friend_invite_test: ALL PASSED\n";
exit($fail ? 1 : 0);
