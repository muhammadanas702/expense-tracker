<?php
/**
 * Add Income / Add Expense page (one shared form, two kinds).
 * income/add-income.php and expense/add-expense.php just call personal_tx_page().
 */
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/lib.php';

function personal_tx_page(string $kind, ?int $editId = null): void
{
    global $conn, $base_url;
    $isEdit = $editId !== null;
    $isExpense = $kind === 'expense';
    secure_session_start();
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../../includes/logging.php';

    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . $base_url . '/auth/login.php');
        exit();
    }
    $user_id = (int) $_SESSION['user_id'];
    $u = $conn->prepare('SELECT name, is_admin FROM users WHERE id = ?');
    $u->execute([$user_id]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: ' . $base_url . '/auth/login.php');
        exit();
    }
    personal_ensure_schema($conn);
    if (isset(ef_modules()['budgets']) && is_file(__DIR__ . '/../budgets/lib.php')) { // optional: budget alerts
        require_once __DIR__ . '/../budgets/lib.php';
        if (is_file(__DIR__ . '/../activity/lib.php')) {
            require_once __DIR__ . '/../activity/lib.php';
        }
    }

    $categories = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book', 'Other' => 'more'];
    if (is_file(__DIR__ . '/../categories/lib.php')) { // optional module: staff can change the list
        require_once __DIR__ . '/../categories/lib.php';
        $categories = categories_active($conn);
    }
    $error = '';
    $old = ['title' => '', 'amount' => '', 'currency' => null, 'category' => isset($categories['Food']) ? 'Food' : (string) array_key_first($categories), 'other_category' => '', 'payment_method' => 'Cash', 'note' => '', 'tags' => '', 'repeat' => ''];

    // New entries start in the currency the person already uses: this month's base currency, else the last one used.
    try {
        $dc = $conn->prepare('SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? ORDER BY `year_month` DESC LIMIT 1');
        $dc->execute([$user_id]);
        $old['currency'] = strtoupper((string) ($dc->fetchColumn() ?: '')) ?: null;
    } catch (Throwable $e) {
        $old['currency'] = null;
    }
    $old['currency'] = $old['currency'] ?? 'PKR';

    $row = null;
    if ($isEdit) {
        $q = $conn->prepare('SELECT * FROM `' . ($isExpense ? 'expenses' : 'income') . '` WHERE id = ? AND user_id = ?');
        $q->execute([$editId, $user_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit('Record not found.');
        }
        $old['title'] = (string) $row['title'];
        $old['amount'] = (string) $row['amount'];
        $old['currency'] = strtoupper(trim((string) ($row['currency'] ?? 'PKR')));
        $old['payment_method'] = personal_payment_method($row['payment_method'] ?? null) ?? '';
        $old['note'] = (string) ($row['note'] ?? '');
        $old['tags'] = implode(', ', personal_tags_list($row['tags'] ?? ''));
        if ($isExpense) {
            $cat = (string) $row['category'];
            if (isset($categories[$cat])) {
                $old['category'] = $cat;
            } else {
                $old['category'] = 'Other';
                $old['other_category'] = $cat;
            }
        }
    }

    // Quick add (pasted SMS) opens this form pre-filled through the address. Only plain suggestions: everything is validated again on save.
    if (!$isEdit && $_SERVER['REQUEST_METHOD'] === 'GET') {
        foreach (['title' => 100, 'amount' => 20, 'note' => 255] as $k => $max) {
            if (isset($_GET[$k]) && is_string($_GET[$k])) {
                $old[$k] = mb_substr(trim($_GET[$k]), 0, $max);
            }
        }
        if (isset($_GET['currency']) && is_string($_GET['currency']) && normalize_currency($_GET['currency']) !== null) {
            $old['currency'] = normalize_currency($_GET['currency']);
        }
        if ($isExpense && isset($_GET['category']) && is_string($_GET['category']) && isset($categories[$_GET['category']])) {
            $old['category'] = $_GET['category'];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($old as $field => $default) {
            $value = $_POST[$field] ?? $default;
            $old[$field] = is_string($value) ? $value : $default;
        }
        $title = clean_text($old['title'], 100);
        $amount = parse_amount($old['amount']);
        $currency = normalize_currency($old['currency']);
        $method = personal_payment_method($old['payment_method']);
        $note = trim($old['note']) === '' ? null : clean_text($old['note'], 255);
        $tags = personal_tags_store($old['tags']);
        $category = $old['category'];

        if (!csrf_valid()) {
            $error = 'Your session expired. Please try again.';
        } elseif ($title === null) {
            $error = 'Please enter a title (up to 100 characters).';
        } elseif ($amount === null) {
            $error = 'Please enter a valid amount greater than zero.';
        } elseif ($currency === null) {
            $error = 'Please choose a supported currency.';
        } elseif ($isExpense && !isset($categories[$category])) {
            $error = 'Please choose a valid category.';
        } elseif (trim($old['note']) !== '' && $note === null) {
            $error = 'The note is too long (255 characters at most).';
        } elseif ($tags === false) {
            $error = 'Tags: up to ' . PERSONAL_MAX_TAGS . ', each up to 20 letters or numbers (for example trip, office).';
        } else {
            if ($isExpense && $category === 'Other') {
                $custom = clean_text($old['other_category'], 50);
                if ($custom !== null) {
                    $category = $custom;
                }
            }
            if ($isEdit) {
                // Keep the PKR value saved with the entry unless the amount or currency really changed.
                $moneyChanged = $row['amount_pkr'] === null
                    || abs($amount - (float) $row['amount']) > 0.004
                    || $currency !== strtoupper((string) ($row['currency'] ?? 'PKR'));
                $pkr = $moneyChanged ? round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2) : (float) $row['amount_pkr'];
                if ($isExpense) {
                    $conn->prepare('UPDATE expenses SET title=?, amount=?, currency=?, amount_pkr=?, category=?, payment_method=?, note=?, tags=? WHERE id=? AND user_id=?')
                        ->execute([$title, $amount, $currency, $pkr, $category, $method, $note, $tags, $editId, $user_id]);
                } else {
                    $conn->prepare('UPDATE income SET title=?, amount=?, currency=?, amount_pkr=?, payment_method=?, note=?, tags=? WHERE id=? AND user_id=?')
                        ->execute([$title, $amount, $currency, $pkr, $method, $note, $tags, $editId, $user_id]);
                }
                logAction($conn, $user_id, $isExpense ? 'edit_expense' : 'edit_income', "Updated record ID: $editId");
                if ($isExpense && function_exists('budgets_check_alerts')) {
                    budgets_check_alerts($conn, $user_id, $category);
                }
                $_SESSION['success'] = ($isExpense ? 'Expense' : 'Income') . ' updated.';
                header('Location: ' . $base_url . '/dashboard.php');
                exit();
            }
            // The PKR value is stored with the entry, so totals do not shift when rates change.
            $amount_pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
            $cid = personal_client_id($_POST['cid'] ?? null);
            $ts = personal_client_time($_POST['ts'] ?? null);
            $when = date('Y-m-d H:i:s', $ts ?? time());
            $year_month = date('Y-m', $ts ?? time());
            $duplicate = false;
            try {
                $conn->beginTransaction();
                if ($cid !== null) { // the same offline entry sent twice is saved once
                    $dup = $conn->prepare('INSERT IGNORE INTO `offline_imports` (`user_id`, `cid`) VALUES (?, ?)');
                    $dup->execute([$user_id, $cid]);
                    $duplicate = $dup->rowCount() === 0;
                }
                $stmt = $conn->prepare('SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?');
                $stmt->execute([$user_id, $year_month]);
                if (!$stmt->fetchColumn()) {
                    $conn->prepare('INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)')
                        ->execute([$user_id, $year_month, $currency]);
                }
                if ($duplicate) {
                    // already saved earlier: nothing more to insert
                } elseif ($isExpense) {
                    $conn->prepare('INSERT INTO `expenses` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`category`,`transaction_date`,`payment_method`,`note`,`tags`) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $category, $when, $method, $note, $tags]);
                } else {
                    $conn->prepare('INSERT INTO `income` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`transaction_date`,`payment_method`,`note`,`tags`) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $when, $method, $note, $tags]);
                }
                if (!$duplicate && $old['repeat'] === '1') {
                    personal_add_recurring($conn, $user_id, $kind, [
                        'title' => $title, 'amount' => $amount, 'currency' => $currency,
                        'category' => $isExpense ? $category : null, 'payment_method' => $method, 'note' => $note,
                    ]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log("ExpenseFlow add $kind failed: " . $e->getMessage());
                $error = 'Something went wrong. Please try again.';
            }
            if ($error === '' && $duplicate) {
                $_SESSION['success'] = 'This entry was already added.';
                header('Location: ' . $base_url . '/dashboard.php');
                exit();
            }
            if ($error === '') {
                if ($isExpense && function_exists('budgets_check_alerts')) {
                    budgets_check_alerts($conn, $user_id, $category);
                }
                logAction($conn, $user_id, $isExpense ? 'add_expense' : 'add_income', "Title: $title, Amount: $amount $currency" . ($old['repeat'] === '1' ? ', repeats monthly' : ''));
                $_SESSION['success'] = ($isExpense ? 'Expense' : 'Income') . ' added successfully!' . ($old['repeat'] === '1' ? ' It will repeat every month.' : '');
                header('Location: ' . $base_url . '/dashboard.php');
                exit();
            }
        }
    }

    $label = $isExpense ? 'Expense' : 'Income';
    $accent = $isExpense ? '#ea580c' : '#0d9f6e';
    ef_page_start([
        'title' => ($isEdit ? 'Edit ' : 'Add ') . $label, // translated by the layout
        'sub' => $isEdit ? 'Change this entry' : ($isExpense ? 'Record money going out' : 'Record money coming in'),
        'active' => $isEdit ? '' : ($isExpense ? 'exp' : 'inc'),
        'user' => $user,
    ]);
    ?>
    <div class="g" style="max-width:640px;margin:0 auto;padding:22px">
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="POST" autocomplete="off" style="--t:<?= e($accent) ?>"<?= $isEdit ? '' : ' data-offline-queue="' . e($kind) . '"' ?>>
        <?= csrf_field() ?>
        <div class="fl"><label for="amount"><?= e(t('Amount')) ?></label>
          <input class="inp amt" id="amount" type="number" inputmode="decimal" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" placeholder="0.00" required></div>
        <div class="fl"><label for="currency"><?= e(t('Currency')) ?></label>
          <select class="inp" id="currency" name="currency" required><?= currency_select_options($old['currency']) ?></select></div>
        <div class="fl"><label for="title"><?= e(t('Title')) ?></label>
          <input class="inp" id="title" type="text" name="title" maxlength="100" value="<?= e($old['title']) ?>" placeholder="<?= e(t($isExpense ? 'e.g. Groceries, Rent' : 'e.g. Salary, Freelance')) ?>" required></div>
        <?php if ($isExpense): ?>
        <div class="fl"><label><?= e(t('Category')) ?></label>
          <div class="chips">
            <?php foreach ($categories as $name => $icon): ?>
              <label><input type="radio" name="category" value="<?= e($name) ?>"<?= $old['category'] === $name ? ' checked' : '' ?>><span class="chip"><?= ef_icon($icon) ?><?= e(t($name)) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="fl" id="other-wrap" hidden><label for="other_category"><?= e(t('Other category name')) ?></label>
          <input class="inp" id="other_category" type="text" name="other_category" maxlength="50" value="<?= e($old['other_category']) ?>" placeholder="<?= e(t('e.g. Gym, Subscription')) ?>"></div>
        <?php endif; ?>
        <div class="fl"><label><?= e(t('Payment method')) ?></label>
          <div class="chips">
            <?php foreach (personal_payment_methods() as $m => $text): ?>
              <label><input type="radio" name="payment_method" value="<?= e($m) ?>"<?= $old['payment_method'] === $m ? ' checked' : '' ?>><span class="chip"><?= e(t($text)) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="fl"><label for="note"><?= e(t('Note')) ?> <span style="text-transform:none;font-weight:500">(<?= e(t('optional')) ?>)</span></label>
          <input class="inp" id="note" type="text" name="note" maxlength="255" value="<?= e($old['note']) ?>" placeholder="<?= e(t('Anything to remember')) ?>"></div>
        <div class="fl"><label for="tags"><?= e(t('Tags')) ?> <span style="text-transform:none;font-weight:500">(<?= e(t('optional, separate with commas')) ?>)</span></label>
          <input class="inp" id="tags" type="text" name="tags" maxlength="100" value="<?= e($old['tags']) ?>" placeholder="<?= e(t('e.g. trip, office')) ?>"></div>
        <?php if (!$isEdit): ?>
        <label class="row" style="cursor:pointer">
          <span><?= ef_icon('rpt') ?> <?= e(t('Repeat every month')) ?><small><?= e(t($isExpense ? 'Adds this expense automatically each month' : 'Adds this income automatically each month')) ?></small></span>
          <b><input class="swc" type="checkbox" name="repeat" value="1"<?= $old['repeat'] === '1' ? ' checked' : '' ?>><i class="sw"></i></b>
        </label>
        <?php endif; ?>
        <button class="btn" type="submit" style="width:100%;margin-top:14px;padding:14px"><?= e(t($isEdit ? 'Save changes' : ($isExpense ? 'Save expense' : 'Save income'))) ?></button>
        <?php if ($isEdit): ?><a class="btn o" href="<?= e($base_url) ?>/dashboard.php" style="display:block;text-align:center;margin-top:10px"><?= e(t('Cancel')) ?></a><?php endif; ?>
      </form>
    </div>
    <?php
    $js = '';
    if ($isExpense) {
        $js = '<script>(function(){var w=document.getElementById("other-wrap");function f(){var c=document.querySelector("input[name=category]:checked");w.hidden=!(c&&c.value==="Other")}document.querySelectorAll("input[name=category]").forEach(function(r){r.addEventListener("change",f)});f()})()</script>';
    }
    ef_page_end(['active' => $isEdit ? '' : ($isExpense ? 'exp' : 'inc'), 'scripts' => $js]);
}
