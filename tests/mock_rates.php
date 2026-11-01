<?php
// Fake exchange-rate service for tests/currency_cache_test.php: 1 USD = 4 EUR... and 400 PKR (so 100 PKR = 0.25 USD).
header('Content-Type: application/json');
echo json_encode(['base' => 'USD', 'rates' => ['USD' => 1, 'PKR' => 400, 'EUR' => 0.9]]);
