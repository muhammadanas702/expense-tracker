<?php
// /includes/CurrencyConverter.php
require_once __DIR__ . '/currencies.php';

class CurrencyConverter
{
    private const API_URL = 'https://api.exchangerate-api.com/v4/latest/USD';
    private const CACHE_TTL = 3600;     // fresh live rates are reused for 1 hour
    private const FAIL_BACKOFF = 900;   // after an attempt, do not ask the API again for 15 minutes
    private const API_TIMEOUT = 2;      // seconds

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

        $json = @file_get_contents(getenv('EXPENSEFLOW_RATES_URL') ?: self::API_URL, false, $context); // the variable is only for tests
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

    /** Folders the rate cache may live in, best first (some hosts do not let PHP write to storage/ itself). */
    public static function cacheDirs(): array
    {
        $s = self::storageDir();
        return [$s, $s . '/schema', rtrim(sys_get_temp_dir(), '/\\') . '/ef-cache-' . md5(__DIR__)];
    }

    /** The first folder PHP can really write to (created when missing), or null. */
    private static function writableCacheDir(): ?string
    {
        foreach (self::cacheDirs() as $i => $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            if ($i === 0 && !is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\n"); // the cache is not meant to be downloaded by visitors
            }
            if (is_writable($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /** Newest file called $name across the cache folders, or null. */
    private static function newestFile(string $name): ?string
    {
        $best = null;
        foreach (self::cacheDirs() as $dir) {
            $f = $dir . '/' . $name;
            if (is_file($f) && ($best === null || filemtime($f) > filemtime($best))) {
                $best = $f;
            }
        }
        return $best;
    }

    private static function loadRates(): void
    {
        if (self::$rates !== null) {
            return;
        }

        $cacheFile = self::newestFile('currency_cache.json');
        $failFile = self::newestFile('currency_cache.fail');

        // 1. Fresh cache.
        if ($cacheFile !== null && (time() - (int) filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = self::readCache($cacheFile);
            if ($cached !== null) {
                self::$rates = $cached;
                return;
            }
        }

        // 2. Ask the live API, but never on every request: the attempt is written down BEFORE asking, so a slow or blocked
        //    API costs one short wait per backoff period, not one per page. Without a writable folder we cannot remember
        //    attempts, so we do not ask at all (the old cache or built-in rates are used).
        $dir = self::writableCacheDir();
        $recentlyTried = $failFile !== null && (time() - (int) filemtime($failFile)) < self::FAIL_BACKOFF;
        if ($dir !== null && !$recentlyTried) {
            @file_put_contents($dir . '/currency_cache.fail', (string) time());
            $live = self::fetchRates();
            if ($live !== null) {
                $tmp = $dir . '/currency_cache.json.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, json_encode($live)) !== false) {
                    @rename($tmp, $dir . '/currency_cache.json');
                }
                foreach (self::cacheDirs() as $d) {
                    @unlink($d . '/currency_cache.fail');
                }
                self::$rates = $live;
                return;
            }
        }

        // 3. An old cache is closer to reality than the built-in numbers.
        $stale = $cacheFile !== null ? self::readCache($cacheFile) : null;
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
