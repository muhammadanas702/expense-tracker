<?php
function getRealIP() {
    $ip = '';
    // Check for shared internet/forwarded IP (for when behind proxy)
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Sometimes multiple IPs are listed; take the first one
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    // Convert IPv6 localhost to IPv4 localhost for readability
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }
    return $ip;
}

function logAction($user_id, $action, $details = null, $client_time = null) {
    global $conn;
    $ip = getRealIP();
    // Use client time if provided, otherwise fallback to server time
    if ($client_time && strtotime($client_time)) {
        $now = $client_time;
    } else {
        $now = date('Y-m-d H:i:s');
    }
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $ip, $now]);
}
?>