<?php
/** Admin > Notifications: send a test/announcement, or save the alert controls. Needs the "set" permission. */
require_once __DIR__ . '/auth.php';
admin_need('set');
require_post_csrf();
require_once __DIR__ . '/../modules/push/nudges.php';
push_ensure_schema($conn);
require_once __DIR__ . '/../modules/friends/username.php';

$do = (string) ($_POST['do'] ?? '');
if ($do === 'save') {
    [$vals, $err] = push_cfg_validate($_POST);
    if ($err) {
        $_SESSION['error'] = $err;
    } else {
        $changes = push_cfg_save($conn, $vals);
        if ($changes) {
            admin_audit($conn, $admin['id'], 'notify_settings', 'Notification settings: ' . implode(', ', $changes));
            $_SESSION['success'] = 'Saved.';
        } else {
            $_SESSION['success'] = 'Nothing changed.';
        }
    }
} elseif ($do === 'send') {
    $title = clean_text(trim((string) ($_POST['title'] ?? '')), 60);
    $body = clean_text(trim((string) ($_POST['body'] ?? '')), 180);
    $pages = ['', 'shared/index.php', 'activity.php', 'reminders.php', 'budgets.php'];
    $url = in_array((string) ($_POST['url'] ?? ''), $pages, true) ? (string) $_POST['url'] : '';
    $to = (string) ($_POST['to'] ?? 'me');
    if (!ef_feature_on('push')) {
        $_SESSION['error'] = 'Phone alerts are switched off in Features on/off.';
    } elseif ($title === null || $title === '' || $body === null || $body === '') {
        $_SESSION['error'] = 'Write a title (up to 60 characters) and a message (up to 180).';
    } else {
        $ids = [];
        $note = '';
        if ($to === 'me') {
            $ids = [(int) $admin['id']];
        } elseif ($to === 'user') {
            friends_username_ensure($conn);
            $who = trim((string) ($_POST['who'] ?? ''));
            $q = $conn->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
            $q->execute([strtolower($who), ltrim(strtolower($who), '@')]);
            $id = (int) $q->fetchColumn();
            if ($id <= 0) {
                $_SESSION['error'] = 'No person found with that e-mail or @username.';
            } else {
                $ids = [$id];
            }
        } elseif ($to === 'all') {
            $last = (int) push_cfg($conn, 'push_last_all');
            if ($last > time() - 600) {
                $_SESSION['error'] = 'An announcement was sent a moment ago. Wait about 10 minutes before sending another.';
            } else {
                $ids = array_map('intval', $conn->query('SELECT DISTINCT d.user_id FROM (SELECT user_id FROM push_subscriptions UNION SELECT user_id FROM app_devices) d
                    WHERE d.user_id NOT IN (SELECT user_id FROM user_settings WHERE notifications = 0) LIMIT 500')->fetchAll(PDO::FETCH_COLUMN));
                push_cfg_save($conn, ['push_last_all' => (string) time()]);
            }
        }
        if ($ids) {
            ignore_user_abort(true);
            @set_time_limit(120);
            $sent = 0;
            foreach (array_chunk($ids, 4) as $chunk) {
                $sent += push_notify($conn, $chunk, $title, $body, $url);
            }
            admin_audit($conn, $admin['id'], 'notify_send', 'Alert "' . mb_substr($title, 0, 40) . '" to ' . ($to === 'all' ? 'everyone (' . count($ids) . ' people)' : ($to === 'me' ? 'myself' : 'one person')) . ': ' . $sent . ' delivered or queued');
            if ($sent > 0) {
                $_SESSION['success'] = 'Sent to ' . $sent . ' device' . ($sent === 1 ? '' : 's') . '. Browsers get it now; Android phones within about 15 to 30 minutes.';
            } else {
                $_SESSION['error'] = 'Nothing was sent: ' . ($to === 'all' ? 'nobody has alerts on' : 'that person has no browser or phone with alerts on') . ', or that channel is switched off below.';
            }
        } elseif (empty($_SESSION['error'])) {
            $_SESSION['error'] = 'Nobody to send to.';
        }
    }
}
header('Location: notifications.php');
exit();
