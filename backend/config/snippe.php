<?php
return [
    'business_code' => env('WIFI_BUSINESS_CODE', 'RJAY_WIFI'),
    'portal_url' => env('WIFI_PORTAL_URL', env('APP_URL', 'http://localhost')),
    'webhook_base_url' => env('SNIPPE_WEBHOOK_BASE_URL', env('APP_URL', 'http://localhost')),
    'allowed_api_hosts' => ['api.snippe.sh'],
    'allowed_checkout_hosts' => ['snippe.me'],
    'timeout' => (int) env('SNIPPE_TIMEOUT', 20),
];
