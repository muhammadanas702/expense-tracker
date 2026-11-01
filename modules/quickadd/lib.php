<?php
/**
 * Reads a pasted payment SMS (Pakistani banks and wallets: "Rs. 1,500.00 debited from A/C ... at FOODPANDA").
 * Pure text work: no database, nothing stored. The result only PRE-FILLS the normal Add form, which validates everything again.
 */
const QUICKADD_MAX_LENGTH = 600;

/** Category guesses: first matching keyword wins. Names must exist in the person's category list to be used. */
function quickadd_category_hints(): array
{
    return [
        'Food' => ['foodpanda', 'kfc', 'mcdonald', 'pizza', 'burger', 'restaurant', 'cafe', 'bakery', 'hardee', 'subway', 'dominos', 'grocery', 'imtiaz', 'carrefour', 'naheed', 'metro cash', 'chaye', 'coffee', 'starbucks'],
        'Transport' => ['careem', 'uber', 'indrive', 'bykea', 'yango', 'petrol', 'pso', 'shell', 'attock', 'total parco', 'fuel', 'motorway', 'metro bus', 'airline', 'pia ', 'airblue', 'serene'],
        'Shopping' => ['daraz', 'khaadi', 'outfitters', 'sapphire', 'alkaram', 'gul ahmed', 'bata', 'mall', 'amazon', 'aliexpress', 'temu', 'shein', 'sana safinaz'],
        'Bills' => ['k-electric', 'k electric', 'kelectric', 'lesco', 'iesco', 'mepco', 'fesco', 'gepco', 'sui', 'sngpl', 'ssgc', 'ptcl', 'stormfiber', 'nayatel', 'wateen', 'jazz', 'zong', 'telenor', 'ufone', 'bill', 'utility', 'recharge', 'netflix', 'spotify', 'rent'],
        'Healthcare' => ['pharmacy', 'hospital', 'clinic', 'medical', 'dawaai', 'chughtai', 'doctor', 'dental'],
        'Education' => ['school', 'university', 'college', 'academy', 'tuition', 'umt', 'udemy', 'coursera'],
        'Entertainment' => ['cinema', 'cinepax', 'netflix', 'steam', 'playstation', 'xbox', 'ticket', 'concert'],
    ];
}

/**
 * Returns [
 *   'ok' => bool (an amount was found),
 *   'type' => 'expense'|'income'|null (null = could not tell),
 *   'amount' => '1500.00'|'', 'currency' => 'PKR', 'title' => string, 'category' => string|'' (a guess, may be empty)
 * ]
 */
function quickadd_parse(string $sms): array
{
    $out = ['ok' => false, 'type' => null, 'amount' => '', 'currency' => 'PKR', 'title' => '', 'category' => ''];
    $text = trim(preg_replace('/\s+/u', ' ', mb_substr($sms, 0, QUICKADD_MAX_LENGTH)));
    if ($text === '') {
        return $out;
    }
    // Amount: "Rs. 1,500.00", "PKR 1500", "1,500 PKR", "USD 12.50", "$12"
    $num = '(\d{1,3}(?:,\d{2,3})*(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';
    $cur = '(PKR|Rs|USD|EUR|GBP|AED|SAR|INR|\$|£|€)';
    $m = null;
    if (preg_match('/\b' . $cur . '\.?\s*' . $num . '/iu', $text, $m) || preg_match('/' . $cur . '\s*' . $num . '/u', $text, $m)) {
        $curRaw = $m[1]; $numRaw = $m[2];
    } elseif (preg_match('/' . $num . '\s*' . $cur . '\b/iu', $text, $m)) {
        $curRaw = $m[2]; $numRaw = $m[1];
    } else {
        return $out;
    }
    $amount = (float) str_replace(',', '', $numRaw);
    if ($amount <= 0 || $amount > 999999999) {
        return $out;
    }
    $map = ['RS' => 'PKR', '$' => 'USD', '£' => 'GBP', '€' => 'EUR'];
    $c = strtoupper($curRaw);
    $out['currency'] = $map[$c] ?? $c;
    if (function_exists('normalize_currency') && normalize_currency($out['currency']) === null) {
        $out['currency'] = 'PKR';
    }
    $out['amount'] = number_format($amount, 2, '.', '');
    $out['ok'] = true;

    $lower = mb_strtolower($text);
    $in = preg_match('/\b(credited|received|deposited|deposit|refund(?:ed)?|salary|cashback|added to|has been received|incoming|sent you)\b/', $lower);
    $outw = preg_match('/\b(debited|debit|spent|paid|payment|purchase|purchased|withdrawn|withdrawal|transferred|sent|charged|bill|pos|atm|deducted|buy)\b/', $lower);
    if ($in && !$outw) {
        $out['type'] = 'income';
    } elseif ($outw && !$in) {
        $out['type'] = 'expense';
    } elseif ($in && $outw) {
        // "You sent Rs 500 to Ali" vs "Rs 500 received from Ali": the first money word decides
        preg_match('/\b(credited|received|deposited|deposit|refund(?:ed)?|salary|cashback|added to|incoming|sent you)\b/', $lower, $a, PREG_OFFSET_CAPTURE);
        preg_match('/\b(debited|debit|spent|paid|payment|purchase|purchased|withdrawn|withdrawal|transferred|sent|charged|bill|pos|atm|deducted|buy)\b/', $lower, $b, PREG_OFFSET_CAPTURE);
        $out['type'] = ($a[0][1] ?? 1e9) < ($b[0][1] ?? 1e9) ? 'income' : 'expense';
    }

    // Who: text after "at / to / from / @ / merchant" up to a stop word. "at" is tried first ("debited from A/C 1 at SHOP"),
    // then "to", then "from"; account or card words are never a name.
    $who = '';
    $stop = '(?=\s+(?:on|ref|avl|bal|available|using|via|trx|txn|tid|id|dated|date|from|at|a\/c|acct|account|card|has|is|was|for|thank|not you)\b|\.(?!\w)|[,;:\n]|\s*$)';
    foreach (['at', 'to', 'from', '@', 'merchant', 'beneficiary', 'towards'] as $prep) {
        $pat = ($prep === '@' ? '@' : '\\b' . $prep . '\\b') . '\\s*([A-Za-z0-9][A-Za-z0-9 &\'.\-\/]{1,40}?)' . $stop;
        if (!preg_match_all('/' . $pat . '/iu', $text, $ms)) {
            continue;
        }
        foreach ($ms[1] as $cand) {
            $cand = trim($cand, " .-/'");
            if ($cand === '' || preg_match('/^(your|you|a\/c|acct|account|card|ac|bank|a|the)\b/i', $cand) || preg_match('/^\d+$/', $cand)) {
                continue;
            }
            $who = $cand;
            break 2;
        }
    }
    $out['title'] = $who !== '' ? mb_substr(mb_convert_case($who, MB_CASE_TITLE, 'UTF-8'), 0, 60) : ($out['type'] === 'income' ? 'Money received' : 'Bank payment');

    if ($out['type'] !== 'income') {
        $hay = ' ' . $lower . ' ';
        foreach (quickadd_category_hints() as $cat => $words) {
            foreach ($words as $w) {
                if (strpos($hay, $w) !== false) {
                    $out['category'] = $cat;
                    break 2;
                }
            }
        }
    }
    return $out;
}

/** The URL of the normal Add page, pre-filled. Values are only suggestions; the form validates them again. */
function quickadd_form_url(string $base, array $p): string
{
    $page = $p['type'] === 'income' ? '/income/add-income.php' : '/expense/add-expense.php';
    $q = ['title' => $p['title'], 'amount' => $p['amount'], 'currency' => $p['currency'], 'note' => 'From SMS'];
    if ($p['type'] !== 'income' && $p['category'] !== '') {
        $q['category'] = $p['category'];
    }
    return $base . $page . '?' . http_build_query($q);
}
