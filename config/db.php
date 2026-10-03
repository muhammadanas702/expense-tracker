<?php

$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = in_array($requestHost, ['localhost', '127.0.0.1'], true);
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
