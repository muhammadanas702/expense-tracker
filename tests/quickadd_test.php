<?php
/** Quick add: reading pasted payment SMS. Usage: php tests/quickadd_test.php (no database needed) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/currencies.php';
require __DIR__ . '/../modules/quickadd/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
// [sms, type, amount, currency, title, category]
$cases = [
    ['Rs. 1,500.00 debited from A/C 1234 at FOODPANDA on 01-11-26. Avl Bal Rs 20,000', 'expense', '1500.00', 'PKR', 'Foodpanda', 'Food'],
    ['PKR 2,000 has been credited to your account from ALI KHAN. Ref 123', 'income', '2000.00', 'PKR', 'Ali Khan', ''],
    ['You have paid Rs 3,450 to K-Electric via Easypaisa. TID 99887', 'expense', '3450.00', 'PKR', 'K-Electric', 'Bills'],
    ['UBL: Purchase of PKR 899.50 at CAREEM on your card ending 4321', 'expense', '899.50', 'PKR', 'Careem', 'Transport'],
    ['Salary of Rs 85,000 received in your account', 'income', '85000.00', 'PKR', 'Money received', ''],
    ['Your card was charged USD 12.99 at NETFLIX.COM', 'expense', '12.99', 'USD', 'Netflix.com', 'Bills'],
    ['Rs.250 sent to Bilal. Fee Rs 0', 'expense', '250.00', 'PKR', 'Bilal', ''],
    ['1,200 PKR spent at Daraz', 'expense', '1200.00', 'PKR', 'Daraz', 'Shopping'],
    ['Rs 500 refund received from Daraz', 'income', '500.00', 'PKR', 'Daraz', ''],
];
foreach ($cases as [$sms, $type, $amt, $cur, $title, $cat]) {
    $r = quickadd_parse($sms);
    $tag = substr($sms, 0, 30);
    ok($r['ok'], "ok: $tag");
    ok($r['type'] === $type, "type $tag => " . var_export($r['type'], true));
    ok($r['amount'] === $amt, "amount $tag => {$r['amount']}");
    ok($r['currency'] === $cur, "currency $tag => {$r['currency']}");
    ok($r['title'] === $title, "title $tag => '{$r['title']}'");
    ok($r['category'] === $cat, "category $tag => '{$r['category']}'");
}
ok(!quickadd_parse('Hello, your OTP is 1234')['ok'], 'no amount: not ok');
ok(!quickadd_parse('')['ok'] && !quickadd_parse('   ')['ok'], 'empty: not ok');
ok(!quickadd_parse('Rs 0 debited')['ok'], 'zero amount: not ok');
ok(quickadd_parse('Rs 100 at the shop')['type'] === null, 'no money word: type unknown');
$long = quickadd_parse(str_repeat('x', 5000) . ' Rs 5');
ok(!$long['ok'], 'only the first 600 characters are read');
$r = quickadd_parse('Rs 1,00,000 debited at <script>alert(1)</script> HOTEL');
ok(!str_contains($r['title'], '<'), 'tags never survive into the title: ' . $r['title']);
$u = quickadd_form_url('', ['type' => 'expense', 'title' => 'A&B "x"', 'amount' => '10.00', 'currency' => 'PKR', 'category' => 'Food']);
ok(str_starts_with($u, '/expense/add-expense.php?') && !str_contains($u, '"') && str_contains($u, 'category=Food'), 'form url encoded: ' . $u);
ok(str_starts_with(quickadd_form_url('', ['type' => 'income', 'title' => 'x', 'amount' => '1', 'currency' => 'PKR', 'category' => 'Food']), '/income/add-income.php?') && !str_contains(quickadd_form_url('', ['type' => 'income', 'title' => 'x', 'amount' => '1', 'currency' => 'PKR', 'category' => 'Food']), 'category'), 'income link has no category');
echo $fails ? "quickadd tests: $fails FAILED\n" : "quickadd tests: ALL PASSED\n";
exit($fails ? 1 : 0);
