<?php

if (
    isset($_SERVER['HTTP_HOST']) &&
    (
        $_SERVER['HTTP_HOST'] === 'localhost' ||
        $_SERVER['HTTP_HOST'] === '127.0.0.1'
    )
) {

    $base_url = "http://localhost/expense-tracker";

} else {

    $base_url = "https://expenseflow.rf.gd";
}