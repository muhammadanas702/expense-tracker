<?php
require_once "auth.php";
require_once "../config/db.php";

$id = $_GET['id'] ?? 0;
$stmt = $conn->prepare("SELECT id, name, email, is_admin FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found.");

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $password = $_POST['password'];

    $update = $conn->prepare("UPDATE users SET name = ?, email = ?, is_admin = ? WHERE id = ?");
    $update->execute([$name, $email, $is_admin, $id]);

    if (!empty($password)) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $conn->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $id]);
    }

    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 400px; border: 1px solid #e2e8f0; }
        input, label { display: block; width: 100%; margin: 0.5rem 0; }
        input[type="text"], input[type="email"], input[type="password"] { padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; margin-top: 1rem; cursor: pointer; }
        .checkbox { display: flex; align-items: center; gap: 8px; }
        .checkbox input { width: auto; margin: 0; }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit User</h2>
    <form method="POST">
        <label>Name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>
        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        <div class="checkbox">
            <input type="checkbox" name="is_admin" id="is_admin" <?= $user['is_admin'] ? 'checked' : '' ?>>
            <label for="is_admin">Admin privileges</label>
        </div>
        <label>New password (leave blank to keep current)</label>
        <input type="password" name="password" placeholder="Enter new password if changing">
        <button type="submit">Update User</button>
    </form>
</div>
</body>
</html>