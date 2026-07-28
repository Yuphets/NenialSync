<?php

return [
    'business_name' => env('RECEIPT_BUSINESS_NAME', 'Nenial Enterprises & Construction'),
    'address' => env('RECEIPT_ADDRESS', 'Calape, Bohol'),
    'contact' => env('RECEIPT_CONTACT'),
    'tin' => env('RECEIPT_TIN'),
    'footer' => env('RECEIPT_FOOTER', 'Thank you for your purchase.'),
    'legal_note' => env('RECEIPT_LEGAL_NOTE', 'System-generated sales receipt.'),
];
