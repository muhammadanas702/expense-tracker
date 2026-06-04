<?php
require_once "auth.php";
require_once "../config/db.php";

$users = $conn->query("SELECT id, name, email, created_at, is_admin FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Admin Panel - Users</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/responsive.css">
    <style>
        /* additional admin-specific styles */
        .edit-btn, .view-btn, .delete-btn {
            display: inline-block;
            margin: 0 4px;
            padding: 4px 10px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.8rem;
        }
        .edit-btn { background: #e0f2fe; color: #0369a1; }
        .view-btn { background: #d1fae5; color: #065f46; }
        .delete-btn { background: #fee2e2; color: #b91c1c; }
        .badge { background: #0f766e; color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
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
            <h1>All Registered Users</h1>
        </div>
        <div class="btn-group">
            <!-- optional extra buttons -->
        </div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr><th>ID</th><th>Name</th><th>Email</th><th>Registered</th><th>Role</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td><?= $u['is_admin'] ? '<span class="badge">Admin</span>' : 'User' ?></td>
                    <td>
                        <a href="edit-user.php?id=<?= $u['id'] ?>" class="edit-btn">Edit</a>
                        <a href="view-user.php?id=<?= $u['id'] ?>" class="view-btn">View</a>
                        <a href="delete-user.php?id=<?= $u['id'] ?>" class="delete-btn" onclick="return confirm('Delete user and all their data?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
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