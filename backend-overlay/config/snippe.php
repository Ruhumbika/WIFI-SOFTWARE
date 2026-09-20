<?php

return [
    'mode' => env('SNIPPE_MODE', 'mock'),
    'base_url' => env('SNIPPE_BASE_URL', 'https://api.snippe.sh'),
    'api_key' => env('SNIPPE_API_KEY'),
    'webhook_secret' => env('SNIPPE_WEBHOOK_SECRET'),
    'webhook_url' => env('SNIPPE_WEBHOOK_URL'),
    'timeout' => (int) env('SNIPPE_TIMEOUT', 20),
];
