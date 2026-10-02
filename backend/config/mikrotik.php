<?php

return [
    'base_url' => env('MIKROTIK_BASE_URL', 'http://10.10.1.1:8081/rest'),
    'username' => env('MIKROTIK_USERNAME', 'rjay-api'),
    'password' => env('MIKROTIK_PASSWORD'),
    'verify_tls' => filter_var(env('MIKROTIK_VERIFY_TLS', false), FILTER_VALIDATE_BOOL),
    'hotspot_login_url' => env('MIKROTIK_HOTSPOT_LOGIN_URL', 'http://rjayshotspot.net/login'),
    'hotspot_allowed_hosts' => array_filter(array_map('trim', explode(',', env('MIKROTIK_HOTSPOT_ALLOWED_HOSTS', 'rjayshotspot.net,10.10.1.1')))),
    'hotspot_server' => env('MIKROTIK_HOTSPOT_SERVER', 'hotspot1'),
    'address_pool' => env('MIKROTIK_ADDRESS_POOL', 'dhcp-pool'),
    'profile_prefix' => env('MIKROTIK_PROFILE_PREFIX', 'RJAY_'),
    'voucher_prefix' => env('MIKROTIK_VOUCHER_PREFIX', 'RJAY-'),
];
