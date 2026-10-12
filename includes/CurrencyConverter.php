<?php
// /includes/CurrencyConverter.php
require_once __DIR__ . '/currencies.php';

class CurrencyConverter
{
    private const API_URL = 'https://api.exchangerate-api.com/v4/latest/USD';
    private const CACHE_TTL = 3600;     // fresh live rates are reused for 1 hour
    private const FAIL_BACKOFF = 300;   // after an API failure, do not retry for 5 minutes
    private const API_TIMEOUT = 4;      // seconds

    private static ?array $rates = null;
    private static bool $usingFallback = false;

    private static function storageDir(): string
    {
        return __DIR__ . '/../storage';
    }

    /** True when the rates in use are NOT fresh live rates (stale cache or built-in approximations). */
    public static function usingFallback(): bool
    {
        self::loadRates();
        return self::$usingFallback;
    }

    private static function validRates($data): bool
    {
        return is_array($data)
            && isset($data['USD'], $data['PKR'])
            && is_numeric($data['USD']) && (float) $data['USD'] > 0
            && is_numeric($data['PKR']) && (float) $data['PKR'] > 0;
    }

    private static function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return self::validRates($data) ? $data : null;
    }

    private static function fetchRates(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::API_TIMEOUT,
                'ignore_errors' => true,
                'header' => "User-Agent: ExpenseFlow\r\nAccept: application/json\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $json = @file_get_contents(self::API_URL, false, $context);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rates']) || !is_array($data['rates'])) {
            return null;
        }

        $rates = [];
        foreach ($data['rates'] as $code => $rate) {
            if (is_string($code) && is_numeric($rate) && (float) $rate > 0) {
                $rates[$code] = (float) $rate;
            }
        }
        return self::validRates($rates) ? $rates : null;
    }

    private static function prepareStorage(): bool
    {
        $dir = self::storageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        // The cache is not meant to be downloaded by visitors.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return is_writable($dir);
    }

    private static function loadRates(): void
    {
        if (self::$rates !== null) {
            return;
        }

        $dir = self::storageDir();
        $cacheFile = $dir . '/currency_cache.json';
        $failFile = $dir . '/currency_cache.fail';

        // 1. Fresh cache.
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = self::readCache($cacheFile);
            if ($cached !== null) {
                self::$rates = $cached;
                return;
            }
        }

        // 2. Ask the live API, unless it failed very recently.
        $recentlyFailed = is_file($failFile) && (time() - (int) filemtime($failFile)) < self::FAIL_BACKOFF;
        if (!$recentlyFailed) {
            $live = self::fetchRates();
            if ($live !== null) {
                if (self::prepareStorage()) {
                    $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (@file_put_contents($tmp, json_encode($live)) !== false) {
                        @rename($tmp, $cacheFile);
                    }
                    @unlink($failFile);
                }
                self::$rates = $live;
                return;
            }
            if (self::prepareStorage()) {
                @file_put_contents($failFile, (string) time());
            }
        }

        // 3. An old cache is closer to reality than the built-in numbers.
        $stale = self::readCache($cacheFile);
        if ($stale !== null) {
            self::$rates = $stale;
            self::$usingFallback = true;
            return;
        }

        // 4. Built-in approximate rates for every supported currency.
        self::$rates = app_fallback_rates();
        self::$usingFallback = true;
    }

    public static function convert($amount, $fromCurrency, $toCurrency)
    {
        $amount = (float) $amount;
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        self::loadRates();
        $from = self::$rates[$fromCurrency] ?? null;
        $to = self::$rates[$toCurrency] ?? null;

        if (!$from || !$to) {
            error_log("ExpenseFlow: no exchange rate for {$fromCurrency} -> {$toCurrency}");
            return $amount;
        }

        return ($amount / $from) * $to;
    }

    /**
     * Value of an expense row in PKR: the amount saved when the expense was
     * added, or (for older rows) the amount converted at today's rate.
     */
    public static function expenseInPkr(array $row): float
    {
        if (isset($row['amount_pkr']) && $row['amount_pkr'] !== null && $row['amount_pkr'] !== '') {
            return (float) $row['amount_pkr'];
        }
        return self::convert($row['amount'] ?? 0, $row['currency'] ?? 'PKR', 'PKR');
    }
}
