<?php
require_once __DIR__ . '/auth.php';
admin_need('export');

$rows = $conn->query("SELECT u.id, u.name, u.email, u.role, u.blocked, u.created_at,
    (SELECT MAX(l.created_at) FROM user_logs l WHERE l.user_id = u.id AND l.action = 'login') AS last_login
    FROM users u ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
admin_audit($conn, $admin['id'], 'export_users', 'Exported the user list (' . count($rows) . ' rows)');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="expenseflow-users-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
fputcsv($out, ['ID', 'Name', 'Email', 'Role', 'Status', 'Joined', 'Last login']);
foreach ($rows as $r) {
    fputcsv($out, [$r['id'], admin_csv_cell($r['name']), admin_csv_cell($r['email']), admin_role_label($r['role']), $r['blocked'] ? 'Blocked' : 'Active', $r['created_at'], $r['last_login'] ?? '']);
}
fclose($out);
