<?php
if (!isset($base_url) || !is_string($base_url) || $base_url === '') {
    require_once __DIR__ . '/../config/app.php';
}

$expenseFlowPwaBaseUrl = rtrim($base_url, '/');
$expenseFlowPwaSafeBaseUrl = htmlspecialchars($expenseFlowPwaBaseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<link rel="manifest" href="<?= $expenseFlowPwaSafeBaseUrl ?>/manifest.json">
<meta name="theme-color" content="#0B2545">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="ExpenseFlow">
<link rel="icon" type="image/x-icon" href="<?= $expenseFlowPwaSafeBaseUrl ?>/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $expenseFlowPwaSafeBaseUrl ?>/assets/icons/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $expenseFlowPwaSafeBaseUrl ?>/assets/icons/apple-touch-icon.png">
<script defer src="<?= $expenseFlowPwaSafeBaseUrl ?>/assets/pwa-register.js" data-app-base="<?= $expenseFlowPwaSafeBaseUrl ?>"></script>
