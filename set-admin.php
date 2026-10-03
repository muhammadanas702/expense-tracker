<?php
session_start();
require_once "config/db.php";

// IMPORTANT: Change this to YOUR email address that you use to login
$admin_email = "anasali2988@gmil.com"; // <-- CHANGE THIS

// Method 1: Update by email
$stmt = $conn->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
if ($stmt->execute([$admin_email])) {
    echo "✅ Admin status updated for email: " . htmlspecialchars($admin_email) . "<br>";
} else {
    echo "❌ Failed to update by email.<br>";
}

// Method 2: If you are already logged in, update by session user_id
if (isset($_SESSION["user_id"])) {
    $user_id = $_SESSION["user_id"];
    $stmt2 = $conn->prepare("UPDATE users SET is_admin = 1 WHERE id = ?");
    if ($stmt2->execute([$user_id])) {
        echo "✅ Admin status updated for user ID: " . $user_id . "<br>";
    } else {
        echo "❌ Failed to update by user ID.<br>";
    }
} else {
    echo "⚠️ You are not logged in. Please log in first, then run this script again.<br>";
}

// Verify the update
$verify = $conn->prepare("SELECT id, email, is_admin FROM users WHERE email = ?");
$verify->execute([$admin_email]);
$user = $verify->fetch(PDO::FETCH_ASSOC);
if ($user) {
    echo "<br>📌 Verification:<br>";
    echo "ID: " . $user['id'] . "<br>";
    echo "Email: " . $user['email'] . "<br>";
    echo "is_admin: " . ($user['is_admin'] ? "1 (YES)" : "0 (NO)") . "<br>";
} else {
    echo "❌ User not found with email: " . $admin_email;
}

echo '<br><br><a href="dashboard.php">Go to Dashboard</a>';
?>