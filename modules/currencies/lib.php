<?php
require_once __DIR__ . '/../../includes/schema.php';
/**
 * Currencies module. Touches only app_currency_hidden.
 * Hiding a currency only removes it from the pick lists for NEW entries. Old entries keep it, and the
 * currency already chosen on a form is always shown. PKR and USD cannot be hidden
 * because the exchange rates are based on them.
 */
const CURRENCY_LOCKED = ['PKR', 'USD'];

function currencies_ensure_schema(PDO $conn): void
{
    if (ef_schema_ok(__FUNCTION__, __FILE__)) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `app_currency_hidden` (
              `code` CHAR(3) NOT NULL,
              PRIMARY KEY (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('ExpenseFlow currencies schema check failed: ' . $e->getMessage());
    }
}

/** code => true for hidden currencies. Never throws; empty on any problem. */
function currencies_hidden_codes(?PDO $conn = null): array
{
    static $cache = null;
    if ($cache !== null && $conn === null) {
        return $cache;
    }
    try {
        $conn = $conn ?: ($GLOBALS['conn'] ?? null);
        if (!$conn instanceof PDO) {
            return [];
        }
        currencies_ensure_schema($conn);
        $cache = [];
        foreach ($conn->query('SELECT code FROM app_currency_hidden')->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if (!in_array($c, CURRENCY_LOCKED, true)) {
                $cache[$c] = true;
            }
        }
        return $cache;
    } catch (Throwable $e) {
        return [];
    }
}

/** Saves the full list of hidden codes (anything unknown or locked is ignored). Returns [turnedOff[], turnedOn[]]. */
function currencies_save_hidden(PDO $conn, array $hidden): array
{
    currencies_ensure_schema($conn);
    $want = [];
    foreach ($hidden as $c) {
        $c = is_string($c) ? strtoupper(trim($c)) : '';
        if (isset(app_currencies()[$c]) && !in_array($c, CURRENCY_LOCKED, true)) {
            $want[$c] = true;
        }
    }
    $now = currencies_hidden_codes($conn);
    $off = array_values(array_diff(array_keys($want), array_keys($now)));
    $on = array_values(array_diff(array_keys($now), array_keys($want)));
    $ins = $conn->prepare('INSERT IGNORE INTO app_currency_hidden (code) VALUES (?)');
    foreach ($off as $c) {
        $ins->execute([$c]);
    }
    $del = $conn->prepare('DELETE FROM app_currency_hidden WHERE code = ?');
    foreach ($on as $c) {
        $del->execute([$c]);
    }
    return [$off, $on];
}

/** How many entries use each currency (expenses + income), for the staff table. code => count */
function currencies_usage(PDO $conn): array
{
    $out = [];
    foreach (['expenses', 'income'] as $t) {
        try {
            foreach ($conn->query("SELECT currency, COUNT(*) FROM `$t` GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR) as $c => $n) {
                $out[$c] = ($out[$c] ?? 0) + (int) $n;
            }
        } catch (Throwable $e) {
        }
    }
    return $out;
}

/** Live "units per 1 USD" rates (or the built-in approximations) and whether they are live. [rates, usingFallback] */
function currencies_rates(): array
{
    require_once __DIR__ . '/../../includes/CurrencyConverter.php';
    $fallback = CurrencyConverter::usingFallback();
    $cache = __DIR__ . '/../../storage/currency_cache.json';
    $rates = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
    return [is_array($rates) ? $rates : app_fallback_rates(), $fallback || !is_array($rates)];
}
