<?php

return [
    'vat_rate' => (float) env('VAT_RATE', 0.12),
    'prices_include_vat' => env('PRICES_INCLUDE_VAT', true),
];
