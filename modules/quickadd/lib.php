<?php
require_once __DIR__ . '/../../includes/schema.php';
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
    $out = ['ok' => false, 'type' => null, 'amount' => '', 'currency' => 'PKR', 'title' => '', 'category' => '', 'mixed' => false];
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
    $outw = preg_match('/\b(debited|debit|spent|paid|payment|purchase|purchased|withdrawn|withdrawal|transferred|sent|charged|bill|pos|atm|deducted|buy)\b/', preg_replace('/\bsent you\b/', 'sent_you', $lower));
    $out['mixed'] = (bool) ($in && $outw);
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


/* ------------------------------------------------------------------ */
/* Part 48c: automatic import with a classifier                        */
/* ------------------------------------------------------------------ */

const QUICKADD_BATCH_MAX = 40;

function quickadd_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS `sms_prefs` (
          `user_id` INT UNSIGNED NOT NULL,
          `auto`    TINYINT NOT NULL DEFAULT 0,
          `since`   DATETIME NULL DEFAULT NULL,
          PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->exec("CREATE TABLE IF NOT EXISTS `sms_imports` (
          `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id`   INT UNSIGNED NOT NULL,
          `msg_hash`  CHAR(40)     NOT NULL,
          `status`    VARCHAR(8)   NOT NULL,
          `reason`    VARCHAR(20)  NOT NULL DEFAULT '',
          `tx_kind`   VARCHAR(8)   NULL DEFAULT NULL,
          `tx_id`     INT UNSIGNED NULL DEFAULT NULL,
          `amount`    DECIMAL(15,2) NULL DEFAULT NULL,
          `currency`  VARCHAR(3)   NOT NULL DEFAULT 'PKR',
          `title`     VARCHAR(100) NOT NULL DEFAULT '',
          `category`  VARCHAR(50)  NOT NULL DEFAULT '',
          `sender`    VARCHAR(40)  NOT NULL DEFAULT '',
          `body`      VARCHAR(600) NULL DEFAULT NULL,
          `sms_date`  DATETIME     NULL DEFAULT NULL,
          `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_sms_hash` (`user_id`, `msg_hash`),
          KEY `idx_sms_status` (`user_id`, `status`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->exec("CREATE TABLE IF NOT EXISTS `sms_devices` (
          `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id`    INT UNSIGNED NOT NULL,
          `token_hash` CHAR(64)     NOT NULL,
          `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_used`  DATETIME     NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_sms_token` (`token_hash`),
          KEY `idx_sms_dev_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $conn->exec("CREATE TABLE IF NOT EXISTS `sms_rules` (
          `user_id`    INT UNSIGNED NOT NULL,
          `sender_key` VARCHAR(40)  NOT NULL,
          PRIMARY KEY (`user_id`, `sender_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ExpenseFlow quickadd schema: ' . $e->getMessage());
    }
}

function quickadd_sender_key(string $sender): string
{
    return substr(strtolower(preg_replace('/[^a-z0-9+]/i', '', $sender)), 0, 40);
}

/**
 * Decides what to do with one message: verdict 'add' (sure: record it), 'review' (ask the person) or 'ignore', plus a short reason.
 * Ignored on purpose: one-time codes, failed or declined payments, bill reminders, money requests, offers and adverts,
 * balance-only alerts, anything without a money word, and senders the person asked to ignore.
 * A message from a normal phone number is never added by itself (a friend's text may mention money): it goes to review.
 */
function quickadd_classify(string $text, string $sender = '', bool $senderIgnored = false): array
{
    $p = quickadd_parse($text);
    $r = ['verdict' => 'ignore', 'reason' => 'no_amount', 'parsed' => $p];
    if (!$p['ok']) {
        return $r;
    }
    $l = mb_strtolower(preg_replace('/\s+/u', ' ', $text));
    $strong = (bool) preg_match('/\b(debited|credited|deducted|withdrawn|charged|spent|purchased?|paid|received|deposited|sent|transferred|refund(?:ed)?|salary)\b/', $l);
    $bank = $sender === '' || preg_match('/[a-z]/i', $sender); // names like "HBL" or "JazzCash", not +923...
    if ($senderIgnored) {
        $r['reason'] = 'sender';
    } elseif (preg_match('/\b(otp|one[- ]?time|verification|login code|passcode|security code|pin)\b/', $l) && !preg_match('/\b(debited|credited)\b/', $l)) {
        $r['reason'] = 'code';
    } elseif (preg_match('/\b(declined|failed|unsuccessful|insufficient|not successful|could not|unable to|rejected)\b/', $l)) {
        $r['reason'] = 'failed';
    } elseif (preg_match('/\b(request(?:ed)? (?:money|rs|pkr|you)|has requested|asked you to pay|payment request)\b/', $l)) {
        $r['reason'] = 'request';
    } elseif (!preg_match('/\b(debited|credited|deducted|charged|spent|purchased?|paid|received|deposited|sent|withdrawn)\b/', $l)
        && preg_match('/\b(due date|is due|bill due|overdue|please pay|kindly pay|pay before|outstanding|reminder|payable|expires?)\b/', $l)) {
        $r['reason'] = 'reminder';
    } elseif (!$strong && preg_match('/\b(offer|promo(?:tion)?|discount|win|prize|lucky|free|subscribe|dial|bundle|package|activate|limited time|apply now|loan|click)\b|www\.|https?:|bit\.ly/', $l)) {
        $r['reason'] = 'promo';
    } elseif (!$strong) {
        $r['reason'] = 'no_money_word';
    } elseif ($p['type'] === null) {
        $r['verdict'] = 'review';
        $r['reason'] = 'direction';
    } elseif ($p['mixed']) {
        $r['verdict'] = 'review';
        $r['reason'] = 'mixed';
    } elseif (!$bank) {
        $r['verdict'] = 'review';
        $r['reason'] = 'person';
    } else {
        $r['verdict'] = 'add';
        $r['reason'] = 'ok';
    }
    return $r;
}

function quickadd_prefs(PDO $conn, int $uid): array
{
    quickadd_ensure_schema($conn);
    $st = $conn->prepare('SELECT auto, since FROM sms_prefs WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['auto' => (int) $r['auto'] === 1, 'since' => $r['since']] : ['auto' => false, 'since' => null];
}

/** Turning auto-add on starts "from now": old messages are never imported. */
function quickadd_set_auto(PDO $conn, int $uid, bool $on): void
{
    quickadd_ensure_schema($conn);
    if ($on) {
        $conn->prepare('INSERT INTO sms_prefs (user_id, auto, since) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE auto = 1, since = NOW()')->execute([$uid]);
    } else {
        $conn->prepare('INSERT INTO sms_prefs (user_id, auto, since) VALUES (?, 0, NULL) ON DUPLICATE KEY UPDATE auto = 0')->execute([$uid]);
        $conn->prepare('DELETE FROM sms_devices WHERE user_id = ?')->execute([$uid]); // phones can no longer send in the background
    }
}

function quickadd_categories(PDO $conn): array
{
    $cats = ['Food', 'Transport', 'Shopping', 'Entertainment', 'Bills', 'Healthcare', 'Education', 'Other'];
    if (is_file(__DIR__ . '/../categories/lib.php')) {
        require_once __DIR__ . '/../categories/lib.php';
        try {
            $cats = array_keys(categories_active($conn));
        } catch (Throwable $e) {
        }
    }
    return $cats;
}

/** The category for an automatic expense: what this person used last time for the same title, else the keyword guess, else Other. */
function quickadd_pick_category(PDO $conn, int $uid, string $title, string $guess): string
{
    $cats = quickadd_categories($conn);
    try {
        $st = $conn->prepare('SELECT category FROM expenses WHERE user_id = ? AND title = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$uid, $title]);
        $prev = (string) $st->fetchColumn();
        if ($prev !== '' && in_array($prev, $cats, true)) {
            return $prev;
        }
    } catch (Throwable $e) {
    }
    if ($guess !== '' && in_array($guess, $cats, true)) {
        return $guess;
    }
    return in_array('Other', $cats, true) ? 'Other' : (string) ($cats[0] ?? 'Other');
}

function quickadd_method(string $text): string
{
    $l = mb_strtolower($text);
    if (str_contains($l, 'easypaisa')) {
        return 'Easypaisa';
    }
    if (str_contains($l, 'jazzcash') || str_contains($l, 'jazz cash')) {
        return 'JazzCash';
    }
    if (preg_match('/\b(card|pos|atm)\b/', $l)) {
        return 'Card';
    }
    return 'Bank';
}

/** Saves one entry like the Add form does (PKR value, monthly base currency, budget alerts). Returns the new id. */
function quickadd_insert_tx(PDO $conn, int $uid, string $kind, array $f): int
{
    require_once __DIR__ . '/../../includes/CurrencyConverter.php';
    require_once __DIR__ . '/../personal/lib.php';
    $amount = (float) $f['amount'];
    $currency = (string) $f['currency'];
    $pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
    $when = date('Y-m-d H:i:s', (int) ($f['ts'] ?? time()));
    $ym = date('Y-m', (int) ($f['ts'] ?? time()));
    $title = mb_substr((string) $f['title'], 0, 100);
    $note = 'From SMS';
    $conn->beginTransaction();
    try {
        $st = $conn->prepare('SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?');
        $st->execute([$uid, $ym]);
        if (!$st->fetchColumn()) {
            $conn->prepare('INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)')->execute([$uid, $ym, $currency]);
        }
        if ($kind === 'expense') {
            $conn->prepare('INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date,payment_method,note) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$uid, $title, $amount, $currency, $pkr, $f['category'], $when, $f['method'], $note]);
        } else {
            $conn->prepare('INSERT INTO income (user_id,title,amount,currency,amount_pkr,transaction_date,payment_method,note) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$uid, $title, $amount, $currency, $pkr, $when, $f['method'], $note]);
        }
        $id = (int) $conn->lastInsertId();
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    if (is_file(__DIR__ . '/../../includes/logging.php')) {
        require_once __DIR__ . '/../../includes/logging.php';
        logAction($conn, $uid, $kind === 'expense' ? 'add_expense' : 'add_income', "From SMS. Title: $title, Amount: $amount $currency");
    }
    if ($kind === 'expense' && isset(ef_modules()['budgets']) && is_file(__DIR__ . '/../budgets/lib.php')) {
        require_once __DIR__ . '/../budgets/lib.php';
        if (is_file(__DIR__ . '/../activity/lib.php')) {
            require_once __DIR__ . '/../activity/lib.php';
        }
        if (function_exists('budgets_check_alerts')) {
            budgets_check_alerts($conn, $uid, (string) $f['category']);
        }
    }
    return $id;
}

/**
 * Handles a batch from the phone: [{id, from, text, date(ms)}, ...]. Each message is claimed by its hash first, so the same
 * message is never added twice (two tabs, a retry, the same SMS read again). Returns counts.
 */
function quickadd_import(PDO $conn, int $uid, array $msgs): array
{
    $res = ['off' => false, 'added' => 0, 'review' => 0, 'ignored' => 0, 'duplicate' => 0, 'old' => 0, 'items' => []];
    $prefs = quickadd_prefs($conn, $uid);
    if (!$prefs['auto'] || !$prefs['since']) {
        $res['off'] = true;
        return $res;
    }
    $sinceTs = strtotime((string) $prefs['since']);
    $rules = [];
    $st = $conn->prepare('SELECT sender_key FROM sms_rules WHERE user_id = ?');
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $rules[$k] = true;
    }
    $n = 0;
    foreach ($msgs as $m) {
        if (++$n > QUICKADD_BATCH_MAX) {
            break;
        }
        if (!is_array($m) || !is_string($m['text'] ?? null) || mb_strlen($m['text']) > QUICKADD_MAX_LENGTH) {
            continue;
        }
        $text = $m['text'];
        $sender = is_string($m['from'] ?? null) ? mb_substr($m['from'], 0, 40) : '';
        $ts = (int) floor(((float) ($m['date'] ?? 0)) / 1000);
        if ($ts < $sinceTs - 60 || $ts > time() + 86400) {
            $res['old']++;
            continue;
        }
        $hash = sha1($uid . '|' . $sender . '|' . $ts . '|' . $text);
        $senderKey = quickadd_sender_key($sender);
        $c = quickadd_classify($text, $sender, $senderKey !== '' && isset($rules[$senderKey]));
        $p = $c['parsed'];
        $status = $c['verdict'] === 'add' ? 'added' : ($c['verdict'] === 'review' ? 'review' : 'ignored');
        $title = $p['ok'] ? $p['title'] : '';
        $claim = $conn->prepare('INSERT IGNORE INTO sms_imports (user_id, msg_hash, status, reason, tx_kind, amount, currency, title, category, sender, body, sms_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))');
        $claim->execute([
            $uid, $hash, $status, $c['reason'], $p['type'], $p['ok'] ? $p['amount'] : null, $p['currency'], $title,
            $status === 'ignored' ? '' : $p['category'], $senderKey, $status === 'review' ? $text : null, $ts,
        ]);
        if ($claim->rowCount() === 0) {
            $res['duplicate']++;
            continue;
        }
        $rowId = (int) $conn->lastInsertId();
        if ($status === 'added') {
            try {
                $cat = $p['type'] === 'expense' ? quickadd_pick_category($conn, $uid, $title, $p['category']) : '';
                $txId = quickadd_insert_tx($conn, $uid, (string) $p['type'], [
                    'title' => $title, 'amount' => $p['amount'], 'currency' => $p['currency'], 'category' => $cat,
                    'method' => quickadd_method($text), 'ts' => $ts,
                ]);
                $conn->prepare('UPDATE sms_imports SET tx_id = ?, category = ? WHERE id = ?')->execute([$txId, $cat, $rowId]);
                if (count($res['items']) < 5) {
                    $res['items'][] = ['kind' => $p['type'], 'title' => $title, 'amount' => $p['amount'], 'currency' => $p['currency']];
                }
            } catch (Throwable $e) {
                error_log('ExpenseFlow SMS import failed: ' . $e->getMessage());
                $conn->prepare('DELETE FROM sms_imports WHERE id = ?')->execute([$rowId]); // not recorded: it can be tried again
                continue;
            }
        }
        $res[$status === 'added' ? 'added' : ($status === 'review' ? 'review' : 'ignored')]++;
    }
    if (random_int(1, 20) === 1) {
        $conn->prepare("DELETE FROM sms_imports WHERE user_id = ? AND created_at < (NOW() - INTERVAL 120 DAY)")->execute([$uid]);
    }
    return $res;
}

function quickadd_review_list(PDO $conn, int $uid): array
{
    quickadd_ensure_schema($conn);
    $st = $conn->prepare("SELECT id, tx_kind, amount, currency, title, category, sender, body, reason, sms_date FROM sms_imports WHERE user_id = ? AND status = 'review' ORDER BY id DESC LIMIT 30");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function quickadd_recent_added(PDO $conn, int $uid): array
{
    quickadd_ensure_schema($conn);
    $st = $conn->prepare("SELECT id, tx_kind, amount, currency, title, category, sms_date FROM sms_imports WHERE user_id = ? AND status = 'added' AND created_at > (NOW() - INTERVAL 14 DAY) ORDER BY id DESC LIMIT 10");
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** A "needs your check" message: $action 'add' (as $kind expense|income) or 'ignore' (optionally always ignore that sender). */
function quickadd_review_resolve(PDO $conn, int $uid, int $id, string $action, string $kind = '', bool $always = false): bool
{
    $st = $conn->prepare("SELECT * FROM sms_imports WHERE id = ? AND user_id = ? AND status = 'review'");
    $st->execute([$id, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ($action === 'add' && in_array($kind, ['expense', 'income'], true) && $row['amount'] !== null) {
        $cat = $kind === 'expense' ? quickadd_pick_category($conn, $uid, (string) $row['title'], (string) $row['category']) : '';
        $txId = quickadd_insert_tx($conn, $uid, $kind, [
            'title' => (string) $row['title'], 'amount' => $row['amount'], 'currency' => $row['currency'], 'category' => $cat,
            'method' => quickadd_method((string) $row['body']), 'ts' => strtotime((string) $row['sms_date']) ?: time(),
        ]);
        $conn->prepare("UPDATE sms_imports SET status = 'added', tx_kind = ?, tx_id = ?, category = ?, body = NULL WHERE id = ?")->execute([$kind, $txId, $cat, $id]);
        return true;
    }
    if ($action === 'ignore') {
        $conn->prepare("UPDATE sms_imports SET status = 'ignored', body = NULL WHERE id = ?")->execute([$id]);
        if ($always && $row['sender'] !== '') {
            $conn->prepare('INSERT IGNORE INTO sms_rules (user_id, sender_key) VALUES (?, ?)')->execute([$uid, $row['sender']]);
        }
        return true;
    }
    return false;
}

/** Takes back an automatic entry: deletes the income/expense and remembers the message so it is not added again. */
function quickadd_undo(PDO $conn, int $uid, int $id): bool
{
    $st = $conn->prepare("SELECT tx_kind, tx_id FROM sms_imports WHERE id = ? AND user_id = ? AND status = 'added'");
    $st->execute([$id, $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['tx_id']) {
        return false;
    }
    $table = $row['tx_kind'] === 'income' ? 'income' : 'expenses';
    $conn->prepare("DELETE FROM `$table` WHERE id = ? AND user_id = ? AND note = 'From SMS'")->execute([$row['tx_id'], $uid]);
    $conn->prepare("UPDATE sms_imports SET status = 'ignored', reason = 'undone' WHERE id = ?")->execute([$id]);
    return true;
}

function quickadd_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        quickadd_ensure_schema($conn);
        foreach (['sms_prefs', 'sms_imports', 'sms_rules', 'sms_devices'] as $t) {
            $conn->prepare("DELETE FROM `$t` WHERE user_id = ?")->execute([$userId]);
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow quickadd cleanup: ' . $e->getMessage());
    }
}


/* ------------------------------------------------------------------ */
/* Part 48d: phone sends new messages in the background                */
/* ------------------------------------------------------------------ */
const QUICKADD_MAX_DEVICES = 5;

/** A secret for ONE phone. It can only send messages to the import endpoint. Only its hash is stored. Returns the token (shown once). */
function quickadd_device_token_new(PDO $conn, int $uid): string
{
    quickadd_ensure_schema($conn);
    $token = bin2hex(random_bytes(32));
    $conn->prepare('INSERT INTO sms_devices (user_id, token_hash) VALUES (?, ?)')->execute([$uid, hash('sha256', $token)]);
    $conn->prepare('DELETE FROM sms_devices WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM sms_devices WHERE user_id = ? ORDER BY id DESC LIMIT ' . QUICKADD_MAX_DEVICES . ') x)')->execute([$uid, $uid]);
    return $token;
}

/** The user id for a phone token, or 0. */
function quickadd_device_user(PDO $conn, string $token): int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return 0;
    }
    quickadd_ensure_schema($conn);
    $st = $conn->prepare('SELECT id, user_id FROM sms_devices WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return 0;
    }
    $conn->prepare('UPDATE sms_devices SET last_used = NOW() WHERE id = ?')->execute([$r['id']]);
    return (int) $r['user_id'];
}
