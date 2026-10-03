<?php
// /includes/CurrencyConverter.php
class CurrencyConverter {
    private static $rates = null;

    // Fetch real exchange rates (cached for 1 hour)
    private static function loadRates() {
        if (self::$rates !== null) return;
        $cacheFile = __DIR__ . '/../storage/currency_cache.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
            self::$rates = json_decode(file_get_contents($cacheFile), true);
            return;
        }
        // Free API – no API key required
        $url = "https://api.exchangerate-api.com/v4/latest/USD";
        $json = @file_get_contents($url);
        if ($json) {
            $data = json_decode($json, true);
            self::$rates = $data['rates'];
            if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0777, true);
            file_put_contents($cacheFile, json_encode(self::$rates));
        } else {
            // Fallback static rates (update as needed)
            self::$rates = [
                'USD' => 1, 'PKR' => 280, 'EUR' => 0.92, 'GBP' => 0.79,
                'AED' => 3.67, 'SAR' => 3.75, 'INR' => 83.5
            ];
        }
    }

    public static function convert($amount, $fromCurrency, $toCurrency) {
        if ($fromCurrency === $toCurrency) return $amount;
        self::loadRates();
        if (!isset(self::$rates[$fromCurrency]) || !isset(self::$rates[$toCurrency])) {
            return $amount;
        }
        $amountInUSD = $amount / self::$rates[$fromCurrency];
        return $amountInUSD * self::$rates[$toCurrency];
    }
}
?>