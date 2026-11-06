<?php
/** Part 48c: which SMS is added, which waits for a check, which is ignored. Usage: php tests/quickadd_classify_test.php (no database) */
$_SERVER['HTTP_HOST'] = 'localhost';
require __DIR__ . '/../includes/currencies.php';
require __DIR__ . '/../modules/quickadd/lib.php';
$fails = 0;
function ok($c, $m) { global $fails; if (!$c) { $fails++; echo "FAIL $m\n"; } }
// [sender, text, verdict, reason-or-type]
$cases = [
    ['HBL', 'Rs. 1,500.00 debited from A/C 1234 at FOODPANDA on 01-11-26. Avl Bal Rs 20,000', 'add', 'expense'],
    ['MEEZAN', 'PKR 2,000 has been credited to your account from ALI KHAN. Ref 123', 'add', 'income'],
    ['Easypaisa', 'You have paid Rs 3,450 to K-Electric via Easypaisa. TID 99887', 'add', 'expense'],
    ['JazzCash', 'Rs 500 received from Bilal. TID 5566', 'add', 'income'],
    ['HBL', 'Your OTP for payment of Rs 1,500 at DARAZ is 482913. Do not share it.', 'ignore', 'code'],
    ['UBL', 'Your verification code is 123456. Rs 500 will be charged', 'ignore', 'code'],
    ['HBL', 'Transaction of Rs 2,000 at DARAZ declined due to insufficient balance', 'ignore', 'failed'],
    ['Jazz', 'Win Rs 50,000 in our lucky draw! Dial *123# now', 'ignore', 'promo'],
    ['Jazz', 'Get Rs 99 weekly bundle offer. Activate now at www.jazz.com.pk', 'ignore', 'promo'],
    ['K-Electric', 'Your bill of Rs 4,200 is due on 05-11-26. Please pay before due date', 'ignore', 'reminder'],
    ['HBL', 'Your available balance is Rs 25,000 as of 01-11-26', 'ignore', 'no_money_word'],
    ['Easypaisa', 'Ali has requested Rs 1,000 from you', 'ignore', 'request'],
    ['HBL', 'Hello, how are you', 'ignore', 'no_amount'],
    ['+923001234567', 'I sent you Rs 500 for lunch', 'review', 'person'],
    ['HBL', 'Rs 100 at the shop', 'ignore', 'no_money_word'],
    ['HBL', 'Rs 5,000 credited to your account. Your card was also charged Rs 50 as fee', 'review', 'mixed'],
    ['UBL', 'Purchase of PKR 899.50 at CAREEM on your card ending 4321', 'add', 'expense'],
    ['8258', 'Rs. 1,200.00 debited from your account at DARAZ on 03-11-26', 'add', 'expense'],
    ['Easypaisa', 'Transaction successful. Rs 500 sent to 03001234567. TID 123', 'add', 'expense'],
    ['HBL', 'Transaction alert: Rs 750 at KFC', 'review', 'direction'],
    ['03001234567', 'Rs 500 received from Ali', 'review', 'person'],
    // Part 63: opt-out footers are not payments
    ['Jazz', 'Aap ka Rs 50 ka offer active ho gaya. To Stop Receiving Messages dial 930', 'ignore', 'promo'],
    ['HBL', 'Rs 700 debited at DARAZ. To stop receiving alerts dial 930', 'add', 'expense'],
];
foreach ($cases as [$from, $text, $verdict, $x]) {
    $c = quickadd_classify($text, $from);
    $tag = substr($text, 0, 34);
    ok($c['verdict'] === $verdict, "verdict $tag => {$c['verdict']}/{$c['reason']}");
    if ($verdict === 'add') {
        ok($c['parsed']['type'] === $x, "type $tag");
    } else {
        ok($c['reason'] === $x, "reason $tag => {$c['reason']}");
    }
}
ok(quickadd_parse('Rs 700 debited at DARAZ. To stop receiving alerts dial 930')['title'] === 'Daraz', 'footer is not part of the title');
ok(quickadd_parse('Payment of Rs 55 to Pkr 55.00 done')['title'] !== 'Pkr 55.00', 'an amount is never the title');
ok(quickadd_parse('Rs 500 sent to 03001234567 via Easypaisa')['title'] !== '', 'phone-number titles still get a title');
$c = quickadd_classify('Rs 500 debited at SHOP', 'HBL', true);
ok($c['verdict'] === 'ignore' && $c['reason'] === 'sender', 'a sender the person ignores is ignored');
ok(quickadd_classify('Rs 500 debited at SHOP', '')['verdict'] === 'add', 'no sender (pasted text) is treated like a bank');
ok(quickadd_sender_key(' HBL-Alerts ') === 'hblalerts' && quickadd_sender_key('+92 300 1234567') === '+923001234567', 'sender keys are normalised');
echo $fails ? "quickadd classify tests: $fails FAILED\n" : "quickadd classify tests: ALL PASSED\n";
exit($fails ? 1 : 0);
