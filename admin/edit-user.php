<?php
require_once "auth.php";
require_once __DIR__ . '/../config/db.php';

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit("Invalid user.");
}

$stmt = $conn->prepare("SELECT id, name, email, is_admin FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit("User not found.");
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_text($_POST['name'] ?? '', 100);
    $email = trim((string) ($_POST['email'] ?? ''));
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    $password = (string) ($_POST['password'] ?? '');

    // keep what was typed if validation fails
    $user['name'] = (string) ($_POST['name'] ?? '');
    $user['email'] = $email;
    $user['is_admin'] = $is_admin;

    if (!csrf_valid()) {
        $error = "Your session expired. Please try again.";
    } elseif ($name === null) {
        $error = "Please enter a name (up to 100 characters).";
    } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== '' && ($policyError = password_policy_error($password)) !== null) {
        $error = $policyError;
    } else {
        $dup = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $id]);

        $wasAdmin = (int) $conn->query("SELECT is_admin FROM users WHERE id = " . (int) $id)->fetchColumn() === 1;
        $adminCount = (int) $conn->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();

        if ($dup->fetch()) {
            $error = "Another account already uses that email address.";
        } elseif ($wasAdmin && !$is_admin && $adminCount <= 1) {
            $error = "This is the only administrator. Make another user an admin first.";
        } else {
            $conn->beginTransaction();
            try {
                $conn->prepare("UPDATE users SET name = ?, email = ?, is_admin = ? WHERE id = ?")
                    ->execute([$name, $email, $is_admin, $id]);

                if ($password !== '') {
                    $conn->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollBack();
                error_log('ExpenseFlow admin edit user failed: ' . $e->getMessage());
                http_response_code(500);
                exit("Could not update the user. Nothing was changed.");
            }

            $changes = 'name/email' . ($password !== '' ? ', password' : '') . ($wasAdmin !== (bool) $is_admin ? ', admin role' : '');
            logAction($conn, $_SESSION['user_id'], 'admin_edit_user', "Edited user ID: $id ($changes)");

            header("Location: index.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/../includes/pwa-head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: white; border-radius: 28px; padding: 2rem; width: 400px; max-width: calc(100% - 32px); border: 1px solid #e2e8f0; }
        input, label { display: block; width: 100%; margin: 0.5rem 0; box-sizing: border-box; }
        input[type="text"], input[type="email"], input[type="password"] { padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 40px; }
        button { background: linear-gradient(135deg, #0f766e, #1d4ed8); color: white; border: none; padding: 0.7rem; border-radius: 40px; width: 100%; margin-top: 1rem; cursor: pointer; }
        .checkbox { display: flex; align-items: center; gap: 8px; }
        .checkbox input { width: auto; margin: 0; }
        .error { background: #fee2e2; color: #b91c1c; padding: 0.5rem 0.8rem; border-radius: 20px; margin-bottom: 0.5rem; }
        .back { display: block; text-align: center; margin-top: 1rem; color: #0f766e; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>Edit User</h2>
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <label>Name</label>
        <input type="text" name="name" maxlength="100" value="<?= e($user['name']) ?>" required>
        <label>Email</label>
        <input type="email" name="email" maxlength="190" value="<?= e($user['email']) ?>" required>
        <div class="checkbox">
            <input type="checkbox" name="is_admin" id="is_admin" <?= $user['is_admin'] ? 'checked' : '' ?>>
            <label for="is_admin">Admin privileges</label>
        </div>
        <label>New password (leave blank to keep current)</label>
        <input type="password" name="password" placeholder="Enter new password if changing" autocomplete="new-password">
        <button type="submit">Update User</button>
    </form>
    <a class="back" href="index.php">← Back to users</a>
</div>
</body>
</html>
