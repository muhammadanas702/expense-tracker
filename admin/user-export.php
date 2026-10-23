<?php
require_once __DIR__ . '/auth.php';
admin_need('export');

$rows = $conn->query("SELECT u.id, u.name, u.email, u.role, u.blocked, u.created_at,
    (SELECT MAX(l.created_at) FROM user_logs l WHERE l.user_id = u.id AND l.action = 'login') AS last_login
    FROM users u ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
admin_audit($conn, $admin['id'], 'export_users', 'Exported the user list (' . count($rows) . ' rows)');

$fmt = (string) ($_GET['format'] ?? 'csv');
if ($fmt === 'xlsx' || $fmt === 'pdf') {
    require_once __DIR__ . '/../includes/export.php';
    if (($fmt === 'pdf' && ef_export_can_pdf()) || ($fmt === 'xlsx' && ef_export_can_xlsx())) {
        $list = [];
        foreach ($rows as $r) {
            $list[] = [(int) $r['id'], $r['name'], $r['email'], admin_role_label($r['role']), $r['blocked'] ? 'Blocked' : 'Active', substr((string) $r['created_at'], 0, 10), ($r['role'] === 'super' && $admin['role'] !== 'super') ? '' : substr((string) ($r['last_login'] ?? ''), 0, 16)];
        }
        $blocked = count(array_filter($rows, fn($r) => $r['blocked']));
        $report = [
            'title' => 'User List',
            'subtitle' => 'Admin export of all registered accounts',
            'meta' => [['Exported by', $admin['name'] ?? 'Admin'], ['Generated', date('d M Y, H:i')]],
            'summary' => [['Total users', count($rows), '', '', '0'], ['Active', count($rows) - $blocked, 'in', '', '0'], ['Blocked', $blocked, 'ex', '', '0']],
            'sections' => [['name' => 'Users', 'columns' => [['ID', 'int', 0.5], ['Name', 'text', 1.6], ['Email', 'text', 2.4], ['Role', 'text', 1], ['Status', 'text', 0.9], ['Joined', 'text', 1.1], ['Last login', 'text', 1.4]], 'rows' => $list, 'tone' => fn($r) => $r[4] === 'Blocked' ? 'ex' : '']],
        ];
        $file = ef_export_filename('Users', $fmt);
        $fmt === 'pdf' ? ef_export_pdf($report, $file) : ef_export_xlsx($report, $file);
        exit();
    }
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="ExpenseFlow-Users-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
fputcsv($out, ['ID', 'Name', 'Email', 'Role', 'Status', 'Joined', 'Last login']);
foreach ($rows as $r) {
    fputcsv($out, [$r['id'], admin_csv_cell($r['name']), admin_csv_cell($r['email']), admin_role_label($r['role']), $r['blocked'] ? 'Blocked' : 'Active', $r['created_at'], ($r['role'] === 'super' && $admin['role'] !== 'super') ? '' : ($r['last_login'] ?? '')]);
}
fclose($out);
