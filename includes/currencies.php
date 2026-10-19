<?php
/**
 * Single source of truth for the currencies ExpenseFlow supports.
 *
 * The same list drives the <select> menus AND the server-side validation,
 * so a currency that is not listed here can never be stored.
 */

const APP_BASE_CURRENCY_FALLBACK = 'PKR';

/** @return array<string, array{0:string,1:string,2:string}> code => [flag, name, group] */
function app_currencies(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    $major = 'Major Currencies';
    $other = 'Other Major Currencies';

    $list = [
        'USD' => ['🇺🇸', 'US Dollar', $major],
        'EUR' => ['🇪🇺', 'Euro', $major],
        'GBP' => ['🇬🇧', 'British Pound', $major],
        'PKR' => ['🇵🇰', 'Pakistani Rupee', $major],
        'INR' => ['🇮🇳', 'Indian Rupee', $major],
        'AED' => ['🇦🇪', 'UAE Dirham', $major],
        'SAR' => ['🇸🇦', 'Saudi Riyal', $major],
        'KRW' => ['🇰🇷', 'South Korean Won', $major],
        'JPY' => ['🇯🇵', 'Japanese Yen', $major],
        'CNY' => ['🇨🇳', 'Chinese Yuan', $major],
        'CAD' => ['🇨🇦', 'Canadian Dollar', $major],
        'AUD' => ['🇦🇺', 'Australian Dollar', $major],
        'CHF' => ['🇨🇭', 'Swiss Franc', $major],
        'NZD' => ['🇳🇿', 'New Zealand Dollar', $major],
        'SGD' => ['🇸🇬', 'Singapore Dollar', $major],
        'MYR' => ['🇲🇾', 'Malaysian Ringgit', $major],
        'THB' => ['🇹🇭', 'Thai Baht', $major],
        'VND' => ['🇻🇳', 'Vietnamese Dong', $major],
        'PHP' => ['🇵🇭', 'Philippine Peso', $major],
        'IDR' => ['🇮🇩', 'Indonesian Rupiah', $major],
        'BDT' => ['🇧🇩', 'Bangladeshi Taka', $major],
        'LKR' => ['🇱🇰', 'Sri Lankan Rupee', $major],
        'NPR' => ['🇳🇵', 'Nepalese Rupee', $major],
        'AFN' => ['🇦🇫', 'Afghan Afghani', $major],
        'TRY' => ['🇹🇷', 'Turkish Lira', $other],
        'RUB' => ['🇷🇺', 'Russian Ruble', $other],
        'BRL' => ['🇧🇷', 'Brazilian Real', $other],
        'ZAR' => ['🇿🇦', 'South African Rand', $other],
        'MXN' => ['🇲🇽', 'Mexican Peso', $other],
        'SEK' => ['🇸🇪', 'Swedish Krona', $other],
        'NOK' => ['🇳🇴', 'Norwegian Krone', $other],
        'DKK' => ['🇩🇰', 'Danish Krone', $other],
        'PLN' => ['🇵🇱', 'Polish Zloty', $other],
        'HKD' => ['🇭🇰', 'Hong Kong Dollar', $other],
        'ILS' => ['🇮🇱', 'Israeli Shekel', $other],
        'KWD' => ['🇰🇼', 'Kuwaiti Dinar', $other],
        'BHD' => ['🇧🇭', 'Bahraini Dinar', $other],
        'OMR' => ['🇴🇲', 'Omani Rial', $other],
        'QAR' => ['🇶🇦', 'Qatari Riyal', $other],
        'EGP' => ['🇪🇬', 'Egyptian Pound', $other],
    ];

    return $list;
}

/** Normalises user input and returns a supported currency code, or null. */
function normalize_currency($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $code = strtoupper(trim($value));
    return isset(app_currencies()[$code]) ? $code : null;
}

/** Renders the <optgroup>/<option> markup for a currency <select>. */
function currency_select_options(?string $selected = null): string
{
    $groups = [];
    $hidden = [];
    if (is_file(__DIR__ . '/../modules/currencies/lib.php')) { // optional module: staff can hide currencies
        require_once __DIR__ . '/../modules/currencies/lib.php';
        $hidden = currencies_hidden_codes();
    }
    foreach (app_currencies() as $code => [$flag, $name, $group]) {
        if (isset($hidden[$code]) && ($selected === null || strtoupper($selected) !== $code)) {
            continue;
        }
        $isSelected = ($selected !== null && strtoupper($selected) === $code) ? ' selected' : '';
        $groups[$group][] = sprintf(
            '<option value="%s"%s>%s %s (%s)</option>',
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
            $isSelected,
            $flag,
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        );
    }

    $html = '';
    foreach ($groups as $label => $options) {
        $html .= '<optgroup label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . implode('', $options)
            . '</optgroup>';
    }
    return $html;
}

/**
 * Approximate "units per 1 USD" used ONLY when the live rate service cannot be
 * reached. Covers every supported currency so conversions never silently
 * return the unconverted amount.
 */
function app_fallback_rates(): array
{
    return [
        'USD' => 1, 'EUR' => 0.861, 'GBP' => 0.744, 'PKR' => 278.64, 'INR' => 95.84,
        'AED' => 3.67, 'SAR' => 3.75, 'KRW' => 1532.42, 'JPY' => 159.95, 'CNY' => 6.79,
        'CAD' => 1.39, 'AUD' => 1.4, 'CHF' => 0.789, 'NZD' => 1.7, 'SGD' => 1.28,
        'MYR' => 4.01, 'THB' => 32.65, 'VND' => 26223.21, 'PHP' => 61.62, 'IDR' => 18036.99,
        'BDT' => 122.69, 'LKR' => 334.66, 'NPR' => 153.35, 'AFN' => 63.23, 'TRY' => 46.04,
        'RUB' => 73.74, 'BRL' => 5.06, 'ZAR' => 16.3, 'MXN' => 17.28, 'SEK' => 9.37,
        'NOK' => 9.33, 'DKK' => 6.42, 'PLN' => 3.65, 'HKD' => 7.83, 'ILS' => 2.89,
        'KWD' => 0.308, 'BHD' => 0.376, 'OMR' => 0.384, 'QAR' => 3.64, 'EGP' => 51.82,
    ];
}
