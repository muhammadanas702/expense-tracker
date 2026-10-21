<?php
/**
 * Profile module helpers. Only touches the user_settings table.
 */
require_once __DIR__ . '/../../includes/schema.php';

function profile_ensure_schema(PDO $conn): void
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
            "CREATE TABLE IF NOT EXISTS `user_settings` (
              `user_id` INT UNSIGNED NOT NULL,
              `savings_goal` TINYINT UNSIGNED NOT NULL DEFAULT 20,
              `notifications` TINYINT(1) NOT NULL DEFAULT 1,
              `setup_done` TINYINT(1) NOT NULL DEFAULT 0,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow profile schema check failed: ' . $e->getMessage());
    }
}

/** Settings for a user. Defaults are returned when no row exists ('exists' tells which). */
function profile_settings(PDO $conn, int $userId): array
{
    profile_ensure_schema($conn);
    $out = ['savings_goal' => 20, 'notifications' => 1, 'setup_done' => 0, 'exists' => false];
    try {
        $stmt = $conn->prepare('SELECT savings_goal, notifications, setup_done FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out = ['savings_goal' => (int) $row['savings_goal'], 'notifications' => (int) $row['notifications'], 'setup_done' => (int) $row['setup_done'], 'exists' => true];
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow settings read failed: ' . $e->getMessage());
    }
    return $out;
}

/** Saves some settings, keeping the others. Allowed keys: savings_goal, notifications, setup_done. */
function profile_save_settings(PDO $conn, int $userId, array $changes): void
{
    $s = profile_settings($conn, $userId);
    if (isset($changes['savings_goal'])) {
        $s['savings_goal'] = max(0, min(100, (int) $changes['savings_goal']));
    }
    if (isset($changes['notifications'])) {
        $s['notifications'] = $changes['notifications'] ? 1 : 0;
    }
    if (isset($changes['setup_done'])) {
        $s['setup_done'] = $changes['setup_done'] ? 1 : 0;
    }
    $conn->prepare(
        'INSERT INTO user_settings (user_id, savings_goal, notifications, setup_done) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE savings_goal = VALUES(savings_goal), notifications = VALUES(notifications), setup_done = VALUES(setup_done)'
    )->execute([$userId, $s['savings_goal'], $s['notifications'], $s['setup_done']]);
}

/** True when the first-run setup card should be offered: never finished and no data yet. */
function profile_needs_setup(PDO $conn, int $userId): bool
{
    $s = profile_settings($conn, $userId);
    if ($s['setup_done']) {
        return false;
    }
    foreach (['income', 'expenses'] as $t) {
        $stmt = $conn->prepare("SELECT 1 FROM `$t` WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return false;
        }
    }
    return true;
}
