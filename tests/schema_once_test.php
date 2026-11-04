<?php
// A schema check runs its DDL once per request: a second call inside a transaction must not commit it. Run: php tests/schema_once_test.php
$_SERVER['HTTP_HOST'] = 'localhost';
putenv('EXPENSEFLOW_NO_SCHEMA_CACHE'); // markers on
require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/assets.php';
require __DIR__ . '/../modules/shared/lib.php';
array_map('unlink', glob(__DIR__ . '/../storage/schema/*') ?: []);
$f = 0;
oauth_ensure_schema($conn);
$conn->beginTransaction();
oauth_ensure_schema($conn);
oauth_on_user_deleted($conn, 999999);
if (!$conn->inTransaction()) { $f++; echo "FAIL second schema check committed the transaction\n"; } else { $conn->rollBack(); }
echo $f ? "$f FAILED\n" : "schema once tests: ALL PASSED\n";
