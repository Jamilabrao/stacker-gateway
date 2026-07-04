<?php

return [

    'api_base_url' => rtrim((string) env('INTEGRAX_API_BASE_URL', 'https://sms.aresfun.com/v1/integration'), '/'),

    'queue' => env('INTEGRAX_QUEUE', 'integrax-sms'),

    'retry' => [
        'tries' => (int) env('INTEGRAX_TRIES', 5),
        'backoff' => [30, 60, 120, 300, 600],
        'timeout' => (int) env('INTEGRAX_TIMEOUT', 60),
    ],

    'http_timeout' => (int) env('INTEGRAX_HTTP_TIMEOUT', 15),

    'max_message_length' => 160,

    'register_url' => 'https://www.integrax.app/auth/register',

    'support_whatsapp' => '+55 11 32808396',

    'defaults' => [
        'sender_from' => '29094',
        'cart_first_delay_minutes' => 10,
        'cart_interval_minutes' => 1440,
        'cart_max_duration_hours' => 72,
        'cart_max_sends' => 3,
        'messages' => [
            'cart_recovery' => 'Oi {nome}! Seu carrinho de {produto} te espera: {link}',
            'order_paid' => '{nome}, pagamento de {valor} confirmado! Obrigado pela compra.',
            'access_granted' => '{nome}, seu acesso a {produto}: {link_acesso}',
            'pix_generated' => '{nome}, PIX de {valor} gerado. Pague para concluir sua compra.',
        ],
    ],

];
