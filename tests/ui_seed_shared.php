<?php
/** Dev helper for tests/ui_shot.py --shared: gives user $argv[1] a group "Murree Trip" with two friends, three shared expenses and one friend link. */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
require __DIR__ . '/../modules/friends/lib.php';
shared_ensure_schema($conn);
if (function_exists('friends_ensure_schema')) friends_ensure_schema($conn);
$me = (int) $argv[1];
$ids = [$me];
foreach (['Ali Raza', 'Sara Khan'] as $i => $n) {
    $conn->prepare('INSERT INTO users (name,email,password) VALUES (?,?,?)')->execute([$n, "seed$i@shot.local", 'x']);
    $ids[] = (int) $conn->lastInsertId();
}
$gid = shared_create_group($conn, $me, 'Murree Trip', 'users');
foreach (array_slice($ids, 1) as $u) shared_add_member($conn, $gid, $u);
$group = shared_group_for($conn, $gid, $me);
foreach ([['Hotel', 24000, 1], ['Fuel', 6500, 0], ['Dinner', 9800, 2]] as [$t, $amt, $payer]) {
    $r = shared_compute_split($amt * 100, 'equal', $ids, array_fill_keys($ids, '1'));
    shared_expense_save($conn, $group, $me, ['title' => $t, 'amount_minor' => $amt * 100, 'currency' => 'PKR', 'paid_by' => $ids[$payer], 'split_type' => 'equal', 'expense_date' => date('Y-m-d'), 'note' => null, 'shares' => $r['shares'], 'weights' => $r['weights']], null);
}
$conn->prepare("INSERT INTO friend_links (requester_id,addressee_id,status) VALUES (?,?,'accepted')")->execute([$me, $ids[1]]);
echo $gid;
