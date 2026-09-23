<?php

return [
    'base_url' => env('CLICKPESA_BASE_URL', 'https://api.clickpesa.com/third-parties'),
    'client_id' => env('CLICKPESA_CLIENT_ID'),
    'api_key' => env('CLICKPESA_API_KEY'),
    'checksum_key' => env('CLICKPESA_CHECKSUM_KEY'),
    'timeout' => env('CLICKPESA_TIMEOUT', 20),
];
