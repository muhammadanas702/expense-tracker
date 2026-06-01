<?php

function getRealIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function logAction($conn, $user_id, $action, $details = null, $client_time = null) {

    $ip = getRealIP();

    $now = ($client_time && strtotime($client_time))
        ? $client_time
        : date('Y-m-d H:i:s');

    $stmt = $conn->prepare("
        INSERT INTO user_logs 
        (user_id, action, details, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([$user_id, $action, $details, $ip, $now]);
}