<?php

return [

    'queue' => env('UTMIFY_QUEUE', 'utmify-tracking'),

    'debug' => filter_var(env('UTMIFY_DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    'retry' => [
        'tries' => (int) env('UTMIFY_TRIES', 10),
        'backoff' => [30, 60, 120, 300, 600, 1200, 1800, 3600],
        'timeout' => (int) env('UTMIFY_TIMEOUT', 60),
    ],

    'http_timeout' => (int) env('UTMIFY_HTTP_TIMEOUT', 15),

];
