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

    $categories = ['Food' => 'food', 'Transport' => 'car', 'Shopping' => 'bag', 'Entertainment' => 'tag', 'Bills' => 'zap', 'Healthcare' => 'heart', 'Education' => 'book', 'Other' => 'more'];
    $error = '';
    $old = ['title' => '', 'amount' => '', 'currency' => null, 'category' => 'Food', 'other_category' => '', 'payment_method' => 'Cash', 'note' => '', 'repeat' => ''];

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
                    $conn->prepare('UPDATE expenses SET title=?, amount=?, currency=?, amount_pkr=?, category=?, payment_method=?, note=? WHERE id=? AND user_id=?')
                        ->execute([$title, $amount, $currency, $pkr, $category, $method, $note, $editId, $user_id]);
                } else {
                    $conn->prepare('UPDATE income SET title=?, amount=?, currency=?, amount_pkr=?, payment_method=?, note=? WHERE id=? AND user_id=?')
                        ->execute([$title, $amount, $currency, $pkr, $method, $note, $editId, $user_id]);
                }
                logAction($conn, $user_id, $isExpense ? 'edit_expense' : 'edit_income', "Updated record ID: $editId");
                $_SESSION['success'] = ($isExpense ? 'Expense' : 'Income') . ' updated.';
                header('Location: ' . $base_url . '/dashboard.php');
                exit();
            }
            // The PKR value is stored with the entry, so totals do not shift when rates change.
            $amount_pkr = round(CurrencyConverter::convert($amount, $currency, 'PKR'), 2);
            $when = date('Y-m-d H:i:s');
            $year_month = date('Y-m');
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare('SELECT `base_currency` FROM `user_monthly_currency` WHERE `user_id` = ? AND `year_month` = ?');
                $stmt->execute([$user_id, $year_month]);
                if (!$stmt->fetchColumn()) {
                    $conn->prepare('INSERT INTO `user_monthly_currency` (`user_id`, `year_month`, `base_currency`) VALUES (?, ?, ?)')
                        ->execute([$user_id, $year_month, $currency]);
                }
                if ($isExpense) {
                    $conn->prepare('INSERT INTO `expenses` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`category`,`transaction_date`,`payment_method`,`note`) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $category, $when, $method, $note]);
                } else {
                    $conn->prepare('INSERT INTO `income` (`user_id`,`title`,`amount`,`currency`,`amount_pkr`,`transaction_date`,`payment_method`,`note`) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([$user_id, $title, $amount, $currency, $amount_pkr, $when, $method, $note]);
                }
                if ($old['repeat'] === '1') {
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
            if ($error === '') {
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
        'title' => ($isEdit ? 'Edit ' : 'Add ') . $label,
        'sub' => $isEdit ? 'Change this entry' : ($isExpense ? 'Record money going out' : 'Record money coming in'),
        'active' => $isEdit ? '' : ($isExpense ? 'exp' : 'inc'),
        'user' => $user,
    ]);
    ?>
    <div class="g" style="max-width:640px;margin:0 auto;padding:22px">
      <?php if ($error !== ''): ?><div class="msg err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="POST" autocomplete="off" style="--t:<?= e($accent) ?>">
        <?= csrf_field() ?>
        <div class="fl"><label for="amount">Amount</label>
          <input class="inp amt" id="amount" type="number" inputmode="decimal" step="0.01" min="0.01" max="99999999.99" name="amount" value="<?= e($old['amount']) ?>" placeholder="0.00" required></div>
        <div class="fl"><label for="currency">Currency</label>
          <select class="inp" id="currency" name="currency" required><?= currency_select_options($old['currency']) ?></select></div>
        <div class="fl"><label for="title">Title</label>
          <input class="inp" id="title" type="text" name="title" maxlength="100" value="<?= e($old['title']) ?>" placeholder="<?= $isExpense ? 'e.g. Groceries, Rent' : 'e.g. Salary, Freelance' ?>" required></div>
        <?php if ($isExpense): ?>
        <div class="fl"><label>Category</label>
          <div class="chips">
            <?php foreach ($categories as $name => $icon): ?>
              <label><input type="radio" name="category" value="<?= e($name) ?>"<?= $old['category'] === $name ? ' checked' : '' ?>><span class="chip"><?= ef_icon($icon) ?><?= e($name) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="fl" id="other-wrap" hidden><label for="other_category">Other category name</label>
          <input class="inp" id="other_category" type="text" name="other_category" maxlength="50" value="<?= e($old['other_category']) ?>" placeholder="e.g. Gym, Subscription"></div>
        <?php endif; ?>
        <div class="fl"><label>Payment method</label>
          <div class="chips">
            <?php foreach (personal_payment_methods() as $m => $text): ?>
              <label><input type="radio" name="payment_method" value="<?= e($m) ?>"<?= $old['payment_method'] === $m ? ' checked' : '' ?>><span class="chip"><?= e($text) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="fl"><label for="note">Note <span style="text-transform:none;font-weight:500">(optional)</span></label>
          <input class="inp" id="note" type="text" name="note" maxlength="255" value="<?= e($old['note']) ?>" placeholder="Anything to remember"></div>
        <?php if (!$isEdit): ?>
        <label class="row" style="cursor:pointer">
          <span><?= ef_icon('rpt') ?> Repeat every month<small>Adds this <?= e(strtolower($label)) ?> automatically each month</small></span>
          <b><input class="swc" type="checkbox" name="repeat" value="1"<?= $old['repeat'] === '1' ? ' checked' : '' ?>><i class="sw"></i></b>
        </label>
        <?php endif; ?>
        <button class="btn" type="submit" style="width:100%;margin-top:14px;padding:14px"><?= $isEdit ? 'Save changes' : 'Save ' . e(strtolower($label)) ?></button>
        <?php if ($isEdit): ?><a class="btn o" href="<?= e($base_url) ?>/dashboard.php" style="display:block;text-align:center;margin-top:10px">Cancel</a><?php endif; ?>
      </form>
    </div>
    <?php
    $js = '';
    if ($isExpense) {
        $js = '<script>(function(){var w=document.getElementById("other-wrap");function f(){var c=document.querySelector("input[name=category]:checked");w.hidden=!(c&&c.value==="Other")}document.querySelectorAll("input[name=category]").forEach(function(r){r.addEventListener("change",f)});f()})()</script>';
    }
    ef_page_end(['active' => $isEdit ? '' : ($isExpense ? 'exp' : 'inc'), 'scripts' => $js]);
}
