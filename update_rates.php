<?php

require_once "config/db.php";

/*
==================================
LIVE CURRENCY API
==================================
*/

$url = "https://api.exchangerate-api.com/v4/latest/PKR";

/*
FETCH API
*/

$response = file_get_contents($url);

if(!$response){

    die("API fetch failed");
}

/*
DECODE JSON
*/

$data = json_decode($response, true);

/*
CHECK RATES
*/

if(!isset($data['rates'])){

    die("Rates not found");
}

/*
GET RATES
*/

$rates = $data['rates'];

/*
SUPPORTED CURRENCIES
*/

$currencies = [

    'PKR',
    'USD',
    'EUR',
    'GBP',
    'SAR',
    'AED',
    'KRW',
    'JPY',
    'CNY',
    'INR',
    'CAD',
    'AUD',
    'TRY',
    'QAR',
    'KWD'
];

/*
SAVE INTO DATABASE
*/

foreach($currencies as $currency){

    /*
    PKR FIX
    */

    if($currency == 'PKR'){

        $rate_to_pkr = 1;

    } else {

        /*
        API gives:
        1 PKR = x USD

        We need:
        1 USD = x PKR
        */

        $apiRate = $rates[$currency] ?? 1;

        $rate_to_pkr =
        round((1 / $apiRate), 2);
    }

    /*
    INSERT / UPDATE
    */

    $stmt = $conn->prepare("

        INSERT INTO exchange_rates
        (
            currency_code,
            rate_to_pkr
        )

        VALUES
        (?, ?)

        ON DUPLICATE KEY UPDATE

        rate_to_pkr=VALUES(rate_to_pkr)

    ");

    $stmt->execute([

        $currency,
        $rate_to_pkr
    ]);
}

echo "Currency rates updated successfully.";
?>