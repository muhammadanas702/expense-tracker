<?php
require_once "auth.php";
require_once "../config/db.php";

$user_filter = $_GET['user_id'] ?? '';
$action_filter = $_GET['action'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($user_filter) {
    $where[] = "l.user_id = ?";
    $params[] = $user_filter;
}
if ($action_filter) {
    $where[] = "l.action = ?";
    $params[] = $action_filter;
}
if ($from_date) {
    $where[] = "DATE(l.created_at) >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $where[] = "DATE(l.created_at) <= ?";
    $params[] = $to_date;
}
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM user_logs l $where_sql");
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $limit);

$sql = "SELECT l.*, u.name as user_name 
        FROM user_logs l 
        JOIN users u ON l.user_id = u.id 
        $where_sql 
        ORDER BY l.created_at DESC 
        LIMIT $offset, $limit";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = $conn->query("SELECT id, name, email FROM users ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>User Logs - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/expense-tracker/assets/responsive.css">
    <style>
        .filter-group { margin-bottom: 0.5rem; }
        @media (min-width: 768px) {
            .filters { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
            .filter-group { margin-bottom: 0; }
        }
        .badge { background: #e2e8f0; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
        .pagination { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; margin-top: 1rem; }
        .pagination a, .pagination span { padding: 0.3rem 0.8rem; background: white; border: 1px solid #cbd5e1; border-radius: 20px; text-decoration: none; color: #0f766e; }
        .pagination .current { background: #0f766e; color: white; border: none; }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="logo">ExpenseFlow Admin</div>
    <a href="index.php">👥 All Users</a>
    <a href="logs.php">📜 User Logs</a>
    <a href="../dashboard.php">← Back to App</a>
    <a href="../auth/logout.php" data-log>🚪 Logout</a>
</div>

<div class="main">
    <div class="top-bar">
        <div class="welcome">
            <h1>📜 User Activity Logs</h1>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters" style="margin-bottom: 1.5rem;">
        <div class="filter-group">
            <label>User</label>
            <select name="user_id">
                <option value="">All users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?> (<?= $u['email'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Action</label>
            <select name="action">
                <option value="">All actions</option>
                <option value="login" <?= $action_filter=='login'?'selected':'' ?>>Login</option>
                <option value="logout" <?= $action_filter=='logout'?'selected':'' ?>>Logout</option>
                <option value="add_income" <?= $action_filter=='add_income'?'selected':'' ?>>Add Income</option>
                <option value="add_expense" <?= $action_filter=='add_expense'?'selected':'' ?>>Add Expense</option>
                <option value="edit_income" <?= $action_filter=='edit_income'?'selected':'' ?>>Edit Income</option>
                <option value="edit_expense" <?= $action_filter=='edit_expense'?'selected':'' ?>>Edit Expense</option>
                <option value="delete_income" <?= $action_filter=='delete_income'?'selected':'' ?>>Delete Income</option>
                <option value="delete_expense" <?= $action_filter=='delete_expense'?'selected':'' ?>>Delete Expense</option>
                <option value="reset_data" <?= $action_filter=='reset_data'?'selected':'' ?>>Reset Data</option>
                <option value="update_profile" <?= $action_filter=='update_profile'?'selected':'' ?>>Update Profile</option>
            </select>
        </div>
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
        </div>
        <div class="filter-group" style="display: flex; gap: 0.5rem;">
            <button type="submit" style="background: #0f766e; color: white; border: none; padding: 0.5rem 1rem; border-radius: 40px;">Filter</button>
            <a href="logs.php" style="background: #e2e8f0; padding: 0.5rem 1rem; border-radius: 40px; text-decoration: none; color: #334155;">Clear filters</a>
        </div>
    </form>

    <!-- Logs table (responsive wrapper) -->
    <div class="table-responsive">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP Address</th></tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="5">No logs found.<?php else: foreach ($logs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td><?= htmlspecialchars($log['user_name']) ?> (ID: <?= $log['user_id'] ?>)</td>
                    <td><span class="badge"><?= str_replace('_', ' ', $log['action']) ?></span></td>
                    <td><?= htmlspecialchars($log['details'] ?? '') ?></td>
                    <td><?= $log['ip_address'] ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    // Mobile sidebar toggle (same as dashboard)
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); }
    function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('active'); }
    if (menuToggle) menuToggle.addEventListener('click', openSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
    if (window.innerWidth >= 768) closeSidebar();
</script>
</body>
</html>