<?php

function convertCurrency($amount, $from, $to) {

    if($from == $to) {

        return $amount;
    }

    $url =
    "https://api.exchangerate.host/convert?from=$from&to=$to&amount=$amount";

    $response = file_get_contents($url);

    $data = json_decode($response, true);

    if(isset($data['result'])) {

        return $data['result'];
    }

    return $amount;
}
?>