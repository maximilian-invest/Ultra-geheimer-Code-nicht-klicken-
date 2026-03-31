<?php

return [
    'api_key' => env('PORTAL_API_KEY'),
    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
    'anthropic_model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    'company' => [
        'name' => env('COMPANY_NAME', 'SR-Homes Immobilien GmbH'),
        'address' => env('COMPANY_ADDRESS'),
        'phone' => env('COMPANY_PHONE'),
        'web' => env('COMPANY_WEB'),
        'fn' => env('COMPANY_FN'),
    ],
];
