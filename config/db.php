<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../includes/schema.php';

$isLocalHost = app_is_local_host();
$privateConfigPath = __DIR__ . '/db.local.php';
$privateConfig = is_file($privateConfigPath) ? require $privateConfigPath : null;
$dbConfig = null;

if (is_array($privateConfig)) {
    $dbConfig = $privateConfig[$isLocalHost ? 'local' : 'production'] ?? null;
    if (!is_array($dbConfig) && isset($privateConfig['host'])) {
        $dbConfig = $privateConfig;
    }
}

if (!is_array($dbConfig)) {
    $dbConfig = [
        'host' => getenv('EXPENSEFLOW_DB_HOST') ?: '',
        'dbname' => getenv('EXPENSEFLOW_DB_NAME') ?: '',
        'username' => getenv('EXPENSEFLOW_DB_USERNAME') ?: '',
        'password' => getenv('EXPENSEFLOW_DB_PASSWORD') ?: '',
    ];
}

if (
    empty($dbConfig['host']) ||
    empty($dbConfig['dbname']) ||
    empty($dbConfig['username']) ||
    !array_key_exists('password', $dbConfig)
) {
    error_log('ExpenseFlow database configuration is missing.');
    http_response_code(500);
    exit('The database is not configured.');
}

try {
    $conn = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['dbname'] . ';charset=utf8mb4',
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('ExpenseFlow database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the database.');
}

// Adds tables/columns that newer code needs when an older database is in use.
ensure_schema($conn);

// A blocked account is signed out on its next request (the block also stops new logins).
if (!empty($_SESSION['user_id']) && schema_has($conn, 'users', 'blocked')) {
    try {
        $blk = $conn->prepare('SELECT blocked FROM users WHERE id = ?');
        $blk->execute([(int) $_SESSION['user_id']]);
        if ((int) $blk->fetchColumn() === 1) {
            unset($_SESSION['user_id'], $_SESSION['user_name']);
            $_SESSION['reset_message'] = 'This account has been blocked. Please contact support.';
            $_SESSION['reset_type'] = 'error';
        }
    } catch (Throwable $e) {
        error_log('ExpenseFlow block check failed: ' . $e->getMessage());
    }
}

// Maintenance mode (optional module): while it is on, only signed-in staff and the login page work.
if (is_file(__DIR__ . '/../modules/settings/lib.php')) {
    require_once __DIR__ . '/../modules/settings/lib.php';
    settings_enforce_maintenance($conn);
}
