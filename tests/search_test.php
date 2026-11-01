<?php
/** Tags and search: tag cleaning, filters, privacy between users. Usage: php tests/search_test.php (TEST database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/shared/lib.php';
require_once __DIR__ . '/../modules/search/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }

ok(personal_tags_store('') === null && personal_tags_store(' , ; ') === null, 'no tags -> null');
ok(personal_tags_store('Trip, #Office work,trip') === ',trip,office-work,', 'cleaned, lower-cased, deduplicated: ' . var_export(personal_tags_store('Trip, #Office work,trip'), true));
ok(personal_tags_store('a,b,c,d,e') === ',a,b,c,d,e,' && personal_tags_store('a,b,c,d,e,f') === false, 'five tags at most');
ok(personal_tags_store(str_repeat('x', 21)) === false && personal_tags_store('bad<tag>') === false && personal_tags_store("x'; DROP") === false, 'long or odd tags refused');
ok(personal_tags_store('سفر') === ',سفر,', 'non-Latin tags allowed');
ok(personal_tags_list(',a,b,') === ['a', 'b'] && personal_tags_list(null) === [], 'tag list');

$f = search_filters(['q' => str_repeat('a', 100), 'type' => 'hack', 'from' => '2026-02-30', 'to' => 'x', 'min' => '-5', 'max' => '10,000', 'tag' => '#Trip']);
ok(mb_strlen($f['q']) === 60 && $f['type'] === 'all' && $f['from'] === '' && $f['to'] === '' && $f['min'] === '' && $f['max'] === '10000' && $f['tag'] === 'trip', 'filters cleaned: ' . json_encode($f));
ok(!search_active(search_filters([])) && search_active(search_filters(['q' => 'x'])), 'search_active');

$conn->exec("DELETE FROM users WHERE email IN ('srch1@s.local','srch2@s.local')");
$conn->exec("INSERT INTO users(name,email,password) VALUES ('S One','srch1@s.local','x'),('S Two','srch2@s.local','x')");
$a = (int) $conn->query("SELECT id FROM users WHERE email='srch1@s.local'")->fetchColumn();
$b = (int) $conn->query("SELECT id FROM users WHERE email='srch2@s.local'")->fetchColumn();
personal_ensure_schema($conn);
$ie = $conn->prepare("INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date,note,tags) VALUES (?,?,?,?,?,?,?,?,?)");
$ii = $conn->prepare("INSERT INTO income (user_id,title,amount,currency,amount_pkr,transaction_date,note,tags) VALUES (?,?,?,?,?,?,?,?)");
$ie->execute([$a, 'Hotel Murree', 20000, 'PKR', 20000, 'Other', '2026-10-05 10:00:00', 'family', ',trip,']);
$ie->execute([$a, 'Lunch', 800, 'PKR', 800, 'Other', '2026-10-06 13:00:00', null, ',trip,office,']);
$ie->execute([$a, 'Groceries 100%', 5000, 'PKR', 5000, 'Other', '2026-09-01 10:00:00', null, null]);
$ie->execute([$a, 'Taxi', 300, 'PKR', 300, 'Other', '2026-10-07 10:00:00', null, ',triple,']);
$ii->execute([$a, 'Trip bonus', 10000, 'PKR', 10000, '2026-10-08 10:00:00', null, ',trip,']);
$ie->execute([$b, 'Hotel Other', 99999, 'PKR', 99999, 'Other', '2026-10-05 10:00:00', null, ',trip,']);
$ie->execute([$a, 'Cat test', 1, 'PKR', 1, 'Other', '2026-10-09 10:00:00', null, null]);
$conn->exec("UPDATE expenses SET category='Food' WHERE user_id=$a AND title IN ('Lunch','Cat test')");
$conn->exec("UPDATE expenses SET category='Travel' WHERE user_id=$a AND title='Hotel Murree'");

$run = fn(array $in) => search_run($conn, $a, search_filters($in));
$titles = fn($r) => array_column($r['rows'], 'title');
$r = $run(['tag' => 'trip']);
ok($r['count'] === 3 && $titles($r) === ['Trip bonus', 'Lunch', 'Hotel Murree'], 'tag trip matches exactly (not "triple"), income too, newest first: ' . json_encode($titles($r)));
ok($r['income_pkr'] === 10000.0 && $r['expense_pkr'] === 20800.0, 'totals');
ok($run(['q' => 'hotel'])['count'] === 1, 'text search finds only my hotel (not the other person\'s)');
ok($titles($run(['q' => 'family'])) === ['Hotel Murree'], 'text search looks in notes');
ok($run(['q' => '100%'])['count'] === 1 && $run(['q' => '%'])['count'] === 1, 'percent sign is literal, not a wildcard');
ok($run(['q' => '_'])['count'] === 0, 'underscore is literal');
ok($run(['q' => 'trip'])['count'] === 4, 'text matches titles and tags as a substring (Trip bonus, trip tags, triple): ' . $run(['q' => 'trip'])['count']);
ok($titles($run(['type' => 'income'])) === ['Trip bonus'] && $run(['type' => 'expense'])['count'] === 5, 'type filter');
ok($titles($run(['category' => 'Food'])) === ['Cat test', 'Lunch'] && $run(['category' => 'Food'])['income_pkr'] === 0.0, 'category filter (income excluded)');
ok($run(['from' => '2026-10-06', 'to' => '2026-10-07'])['count'] === 2, 'date range includes both end days');
ok($titles($run(['min' => '5000', 'max' => '20000'])) === ['Trip bonus', 'Hotel Murree', 'Groceries 100%'], 'amount range: ' . json_encode($titles($run(['min' => '5000', 'max' => '20000']))));
ok($run(['tag' => 'trip', 'type' => 'expense', 'min' => '1000'])['count'] === 1, 'filters combine');
ok(search_run($conn, $b, search_filters(['tag' => 'trip']))['count'] === 1, 'other person only sees their own');
ok(search_user_tags($conn, $a) === ['trip', 'office', 'triple'] || search_user_tags($conn, $a)[0] === 'trip', 'my tags, most used first');
ok(!in_array('trip', array_diff(search_user_tags($conn, 0), []), true), 'nobody else gets my tags');

$conn->exec("DELETE FROM expenses WHERE user_id IN ($a,$b)"); $conn->exec("DELETE FROM income WHERE user_id IN ($a,$b)");
$conn->exec("DELETE FROM users WHERE id IN ($a,$b)");
echo $fails ? "search tests: $fails FAILED\n" : "search tests: ALL PASSED\n";
exit($fails ? 1 : 0);
