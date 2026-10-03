<?php
session_start();
require_once "config/db.php"; // Make sure the path to your db.php is correct

// --- IMPORTANT: Change this to YOUR email address ---
$admin_email = "anasali2988@gmil.com";
// ---------------------------------------------------

$stmt = $conn->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
if ($stmt->execute([$admin_email])) {
    echo "<h2>✅ Admin status restored for {$admin_email}!</h2>";
    echo "<p>You can now <a href='dashboard.php'>go to the dashboard</a>.</p>";
} else {
    echo "<h2>❌ Failed to update. Please check the email address.</h2>";
}
?>