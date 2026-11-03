<?php
/**
 * Getting-started checklist. Which steps are done is read from what the person already created, so nothing is stored.
 * Returns [] (show nothing) when the account is older than GS_MAX_DAYS or every step is done.
 */
const GS_MAX_DAYS = 45;

/** @return array<int, array{key:string,label:string,url:string,done:bool}> */
function gettingstarted_steps(PDO $conn, int $userId, string $base): array
{
    try {
        $st = $conn->prepare('SELECT DATEDIFF(NOW(), created_at) FROM users WHERE id = ?');
        $st->execute([$userId]);
        $age = $st->fetchColumn();
        if ($age === false || (int) $age > GS_MAX_DAYS) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }
    $defs = [['expense', 'Add your first expense', '/expense/add-expense.php', 'expenses'], ['income', 'Add your income', '/income/add-income.php', 'income']];
    $mods = [['budgets', 'budget', 'Set a monthly budget', '/budgets.php', 'budgets'], ['goals', 'goal', 'Create a savings goal', '/goals.php', 'savings_goals'], ['reminders', 'reminder', 'Add a bill reminder', '/reminders.php', 'reminders']];
    foreach ($mods as [$mod, $key, $label, $url, $table]) {
        if (isset(ef_modules()[$mod])) {
            $defs[] = [$key, $label, $url, $table];
        }
    }
    $parts = [];
    foreach ($defs as [$key, , , $table]) {
        $parts[] = "SELECT '$key' AS k FROM `$table` WHERE user_id = :u LIMIT 1"; // names come from the list above, never from input
    }
    $done = [];
    try {
        $q = $conn->prepare('(' . implode(') UNION ALL (', $parts) . ')');
        $q->execute(['u' => $userId]);
        $done = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        // a table of an optional module does not exist yet (it is created on first use): treat that step as not done
        foreach ($defs as [$key, , , $table]) {
            try {
                $one = $conn->prepare("SELECT 1 FROM `$table` WHERE user_id = ? LIMIT 1");
                $one->execute([$userId]);
                if ($one->fetchColumn()) {
                    $done[$key] = true;
                }
            } catch (Throwable $e2) {
            }
        }
    }
    $out = [];
    $left = 0;
    foreach ($defs as [$key, $label, $url]) {
        $isDone = isset($done[$key]);
        $left += $isDone ? 0 : 1;
        $out[] = ['key' => $key, 'label' => $label, 'url' => $base . $url, 'done' => $isDone];
    }
    return $left === 0 ? [] : $out;
}
