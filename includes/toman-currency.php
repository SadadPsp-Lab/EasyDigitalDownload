<?php

if (!function_exists('irg_add_toman_currency')) {
    add_filter('edd_currencies', 'add_toman_currency');
    function add_toman_currency($currencies)
    {
        $currencies['IRT'] = 'تومان';
        return $currencies;
    }
}

add_filter('edd_sanitize_amount_decimals', function ($decimals) {
    global $edd_options;
    $currency = function_exists('edd_get_currency') ? edd_get_currency() : '';
    if ($edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL')
        return $decimals = 0;
    return $decimals;
});

add_filter('edd_format_amount_decimals', function ($decimals) {
    global $edd_options;
    $currency = function_exists('edd_get_currency') ? edd_get_currency() : '';
    if ($edd_options['currency'] == 'IRT' || $currency == 'IRT' || $edd_options['currency'] == 'RIAL' || $currency == 'RIAL')
        return $decimals = 0;
    return $decimals;
});

if (function_exists('per_number')) {
    add_filter('edd_irt_currency_filter_after', 'per_number', 10, 2);
}

add_filter('edd_irt_currency_filter_after', 'toman_postfix', 10, 2);
function toman_postfix($price)
{
    return str_replace('IRT', 'تومان', $price);
}

add_filter('edd_rial_currency_filter_after', 'rial_postfix', 10, 2);
function rial_postfix($price)
{
    return str_replace('RIAL', 'ریال', $price);
}