<?php

declare(strict_types=1);

/*
 * ISO 4217 numeric → alphabetic codes, for GS1 AI 393n (price with currency) on scale labels.
 * Matched against currencies.iso_code (then currencies.code). Add codes as needed.
 */
return [
    '008' => 'ALL', '012' => 'DZD', '036' => 'AUD', '048' => 'BHD', '124' => 'CAD', '156' => 'CNY',
    '356' => 'INR', '368' => 'IQD', '376' => 'ILS', '392' => 'JPY', '400' => 'JOD', '414' => 'KWD',
    '422' => 'LBP', '504' => 'MAD', '512' => 'OMR', '634' => 'QAR', '682' => 'SAR', '756' => 'CHF',
    '760' => 'SYP', '784' => 'AED', '788' => 'TND', '818' => 'EGP', '826' => 'GBP', '840' => 'USD',
    '949' => 'TRY', '978' => 'EUR', '986' => 'BRL', '710' => 'ZAR', '752' => 'SEK', '578' => 'NOK',
    '208' => 'DKK', '985' => 'PLN',
];
