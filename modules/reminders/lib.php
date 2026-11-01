<?php
require_once __DIR__ . '/../../includes/schema.php';
require_once __DIR__ . '/../../includes/CurrencyConverter.php';
/**
 * Reminders (table `reminders`). A reminder has a due date and optionally repeats every month.
 * Marking it paid moves a monthly one to its next date (or finishes a one-time one) and can record the expense.
 * Bell notifications: once when it is within 3 days (kind reminder_soon) and once when it is late (reminder_late);
 * `soon_for` / `late_for` remember the due date already announced, so nothing repeats.
 */
const REMINDERS_MAX_PER_USER = 60;
const REMINDERS_SOON_DAYS = 3;

function reminders_ensure_schema(PDO $conn): void
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
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `reminders` (
              `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
              `user_id`    INT UNSIGNED  NOT NULL,
              `title`      VARCHAR(60)   NOT NULL,
              `amount`     DECIMAL(15,2) NULL DEFAULT NULL,
              `currency`   VARCHAR(3)    NOT NULL DEFAULT 'PKR',
              `due_date`   DATE          NOT NULL,
              `monthly`    TINYINT       NOT NULL DEFAULT 0,
              `active`     TINYINT       NOT NULL DEFAULT 1,
              `soon_for`   DATE          NULL DEFAULT NULL,
              `late_for`   DATE          NULL DEFAULT NULL,
              `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_reminder_user` (`user_id`, `active`, `due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        ef_schema_add_missing($conn, 'reminders', [
            'user_id' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'title' => "VARCHAR(60) NOT NULL DEFAULT ''", 'amount' => 'DECIMAL(15,2) NULL DEFAULT NULL',
            'currency' => "VARCHAR(3) NOT NULL DEFAULT 'PKR'", 'due_date' => 'DATE NULL DEFAULT NULL', 'monthly' => 'TINYINT NOT NULL DEFAULT 0',
            'active' => 'TINYINT NOT NULL DEFAULT 1', 'soon_for' => 'DATE NULL DEFAULT NULL', 'late_for' => 'DATE NULL DEFAULT NULL',
        ]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow reminders schema check failed: ' . $e->getMessage());
    }
}

/** Validates the form. Returns [clean|null, error|null]. */
function reminders_validate(array $in, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $title = trim(preg_replace('/\s+/', ' ', (string) ($in['title'] ?? '')));
    if ($title === '' || mb_strlen($title) > 60) {
        return [null, 'Give the reminder a name (60 characters at most).'];
    }
    $amount = null;
    $raw = str_replace(',', '', trim((string) ($in['amount'] ?? '')));
    if ($raw !== '') {
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            return [null, 'The amount must be above zero (or leave it empty).'];
        }
        $amount = round((float) $raw, 2);
    }
    $cur = function_exists('normalize_currency') ? normalize_currency((string) ($in['currency'] ?? '')) : null;
    if ($cur === null) {
        return [null, 'Please choose a currency.'];
    }
    $d = trim((string) ($in['due_date'] ?? ''));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d || $dt < $today->modify('-1 year') || $dt > $today->modify('+10 years')) {
        return [null, 'Choose the due date.'];
    }
    return [['title' => $title, 'amount' => $amount, 'currency' => $cur, 'due_date' => $d, 'monthly' => !empty($in['monthly']) ? 1 : 0], null];
}

/** Same day next month, clamped to the end of a shorter month (31 Jan -> 28/29 Feb). */
function reminders_next_month(string $date): string
{
    $d = new DateTimeImmutable($date);
    $day = (int) $d->format('j');
    $first = $d->modify('first day of next month');
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')))->format('Y-m-d');
}

function reminders_create(PDO $conn, int $userId, array $v): int
{
    reminders_ensure_schema($conn);
    $n = $conn->prepare('SELECT COUNT(*) FROM reminders WHERE user_id = ? AND active = 1');
    $n->execute([$userId]);
    if ((int) $n->fetchColumn() >= REMINDERS_MAX_PER_USER) {
        return 0;
    }
    $conn->prepare('INSERT INTO reminders (user_id, title, amount, currency, due_date, monthly) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $v['title'], $v['amount'], $v['currency'], $v['due_date'], $v['monthly']]);
    return (int) $conn->lastInsertId();
}

function reminders_delete(PDO $conn, int $userId, int $id): void
{
    reminders_ensure_schema($conn);
    $conn->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
}

/**
 * "Paid": a monthly reminder moves to its next date (skipping dates already in the past), a one-time one is switched off.
 * With $record = true and an amount, the payment is also saved as an expense (category Bills). Returns an error text or null.
 */
function reminders_mark_paid(PDO $conn, int $userId, int $id, bool $record, ?DateTimeImmutable $today = null): ?string
{
    reminders_ensure_schema($conn);
    $today = $today ?? new DateTimeImmutable('today');
    $q = $conn->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ? AND active = 1');
    $q->execute([$id, $userId]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return 'That reminder was not found.';
    }
    $conn->beginTransaction();
    try {
        if ((int) $r['monthly'] === 1) {
            $next = reminders_next_month($r['due_date']);
            $guard = 0;
            while ($next <= $today->format('Y-m-d') && $guard++ < 36) {
                $next = reminders_next_month($next);
            }
            $upd = $conn->prepare('UPDATE reminders SET due_date = ? WHERE id = ? AND user_id = ? AND due_date = ?');
            $upd->execute([$next, $id, $userId, $r['due_date']]);
        } else {
            $upd = $conn->prepare('UPDATE reminders SET active = 0 WHERE id = ? AND user_id = ? AND active = 1');
            $upd->execute([$id, $userId]);
        }
        if ($upd->rowCount() !== 1) { // another tab was faster: do not record twice
            $conn->rollBack();
            return null;
        }
        if ($record && $r['amount'] !== null) {
            $pkr = round(CurrencyConverter::convert((float) $r['amount'], $r['currency'], 'PKR'), 2);
            $conn->prepare('INSERT INTO expenses (user_id,title,amount,currency,amount_pkr,category,transaction_date,payment_method,note) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$userId, $r['title'], $r['amount'], $r['currency'], $pkr, 'Bills', date('Y-m-d H:i:s'), 'cash', 'From a reminder']);
        }
        $conn->commit();
        return null;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ExpenseFlow reminder paid failed: ' . $e->getMessage());
        return 'Something went wrong. Please try again.';
    }
}

/** Active reminders, earliest first, with days (negative = late) and state (late|today|soon|later). */
function reminders_list(PDO $conn, int $userId, ?DateTimeImmutable $today = null): array
{
    reminders_ensure_schema($conn);
    $today = $today ?? new DateTimeImmutable('today');
    $q = $conn->prepare('SELECT id, title, amount, currency, due_date, monthly FROM reminders WHERE user_id = ? AND active = 1 ORDER BY due_date, id');
    $q->execute([$userId]);
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days = (int) $today->diff(new DateTimeImmutable($r['due_date']))->format('%r%a');
        $out[] = ['id' => (int) $r['id'], 'title' => $r['title'], 'amount' => $r['amount'] === null ? null : (float) $r['amount'], 'currency' => $r['currency'],
            'due_date' => $r['due_date'], 'monthly' => (int) $r['monthly'] === 1, 'days' => $days,
            'state' => $days < 0 ? 'late' : ($days === 0 ? 'today' : ($days <= REMINDERS_SOON_DAYS ? 'soon' : 'later'))];
    }
    return $out;
}

/** Sends the bell notifications that are due (each once per due date). Never throws. Returns how many were sent. */
function reminders_check_due(PDO $conn, int $userId, ?DateTimeImmutable $today = null): int
{
    $sent = 0;
    try {
        if (!function_exists('activity_record')) {
            return 0;
        }
        foreach (reminders_list($conn, $userId, $today) as $r) {
            if ($r['state'] === 'later') {
                continue;
            }
            $late = $r['state'] === 'late';
            $col = $late ? 'late_for' : 'soon_for';
            $claim = $conn->prepare("UPDATE reminders SET $col = ? WHERE id = ? AND user_id = ? AND ($col IS NULL OR $col <> ?)");
            $claim->execute([$r['due_date'], $r['id'], $userId, $r['due_date']]);
            if ($claim->rowCount() === 1) {
                activity_record($conn, 0, $userId, $late ? 'reminder_late' : 'reminder_soon',
                    ['title' => $r['title'], 'amount_minor' => max(0, $r['days']), 'by' => 0], [$userId]);
                $sent++;
            }
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow reminder check failed: ' . $e->getMessage());
    }
    return $sent;
}

function reminders_on_user_deleted(PDO $conn, int $userId): void
{
    try {
        reminders_ensure_schema($conn);
        $conn->prepare('DELETE FROM reminders WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow reminders cleanup: ' . $e->getMessage());
    }
}
