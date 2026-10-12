<?php

require_once __DIR__ . '/security.php';

/** Kept for backwards compatibility. Only REMOTE_ADDR is trusted (forwarded headers can be forged). */
function getRealIP()
{
    return client_ip();
}

/**
 * Writes one row to the activity log.
 *
 * $client_time is accepted for backwards compatibility but ignored: the log
 * always uses the server clock so entries cannot be back-dated by a client.
 * A logging problem must never break the action the user is doing, so errors
 * are only written to the server's error log.
 */
function logAction($conn, $user_id, $action, $details = null, $client_time = null)
{
    try {
        if ($details !== null) {
            $details = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $details) ?? '', 0, 500, 'UTF-8');
        }

        $stmt = $conn->prepare("
            INSERT INTO user_logs
            (user_id, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([$user_id, $action, $details, client_ip(), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('ExpenseFlow activity log failed: ' . $e->getMessage());
    }
}
