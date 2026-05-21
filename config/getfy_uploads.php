<?php

/**
 * Limites de upload (Laravel `max:` em ficheiros = kilobytes).
 * O PHP (upload_max_filesize / post_max_size) deve ser >= ao maior ficheiro permitido.
 */
return [
    'php' => [
        'upload_max_filesize' => env('GETFY_UPLOAD_MAX_FILESIZE', '64M'),
        'post_max_size' => env('GETFY_POST_MAX_SIZE', '70M'),
        'memory_limit' => env('GETFY_MEMORY_LIMIT', '256M'),
        'max_execution_time' => (int) env('GETFY_UPLOAD_MAX_EXECUTION_TIME', 300),
        'max_input_time' => (int) env('GETFY_UPLOAD_MAX_INPUT_TIME', 300),
    ],

    'member_builder' => require __DIR__.'/member_builder_uploads.php',

    'kyc_max_kb' => (int) env('KYC_UPLOAD_MAX_KB', 20480),
];
