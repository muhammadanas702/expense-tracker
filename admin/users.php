<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once "auth.php";
require_once "../config/db.php";

// Fetch all users (exclude password)
$users = $conn->query("SELECT id, name, email, created_at, is_admin FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/responsive.css">

<head>
    <link rel="icon" type="image/png" href="/favicon.png">
    <meta charset="UTF-8">
    <title>Manage Users – Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: #f5f7fc; display: flex; }
        .sidebar { width: 260px; background: white; border-right: 1px solid #e2e8f0; height: 100vh; position: sticky; top: 0; padding: 2rem 1.5rem; }
        .sidebar h2 { font-size: 1.3rem; margin-bottom: 2rem; color: #0f172a; }
        .sidebar a { display: block; padding: 10px 0; color: #334155; text-decoration: none; margin: 5px 0; }
        .sidebar a:hover { color: #0f766e; }
        .main { flex: 1; padding: 2rem; }
        h1 { margin-bottom: 1.5rem; }
        table { width: 100%; background: white; border-radius: 24px; border-collapse: collapse; overflow: hidden; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        .actions a { margin-right: 10px; text-decoration: none; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
        .edit-btn { background: #e0f2fe; color: #0369a1; }
        .delete-btn { background: #fee2e2; color: #b91c1c; }
        .view-btn { background: #d1fae5; color: #065f46; }
        .badge { background: #0f766e; color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
    </style>
</head>
<body>
<div class="sidebar">
    <h2>📊 Admin Panel</h2>
    <a href="index.php">🏠 Dashboard</a>
    <a href="users.php">👥 Manage Users</a>
    <a href="../dashboard.php">← Back to App</a>
    <a href="../auth/logout.php">🚪 Logout</a>
</div>
<div class="main">
    <h1>Manage Users</h1>
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
                <td class="actions">
                    <a href="edit-user.php?id=<?= $u['id'] ?>" class="edit-btn">Edit</a>
                    <a href="view-user.php?id=<?= $u['id'] ?>" class="view-btn">View</a>
                    <a href="delete-user.php?id=<?= $u['id'] ?>" class="delete-btn" onclick="return confirm('Delete user and all their data?')">Delete</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>