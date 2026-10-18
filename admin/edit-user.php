<?php
// Editing moved to the user's profile page (Part 11b).
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
header('Location: view-user.php' . ($id ? '?id=' . $id : ''));
exit();
