<?php

use App\Gateways\GatewayRegistry;
use Plugins\OnlyUp\OnlyUpDriver;
use Plugins\OnlyUp\OnlyUpWebhookHandler;

return function ($app, \Illuminate\Contracts\Events\Dispatcher $events): void {
    GatewayRegistry::register([
        'slug' => 'onlyup',
        'name' => 'OnlyUp',
        'image' => 'images/gateways/onlyup.png',
        'methods' => ['pix'],
        'scope' => 'national',
        'country' => 'br',
        'country_name' => 'Brasil',
        'country_flag' => 'brasil.png',
        'signup_url' => 'https://finance.onlyup.com',
        'driver' => OnlyUpDriver::class,
        'webhook_handler' => OnlyUpWebhookHandler::class,
        'credential_keys' => [
            ['key' => 'pix_key', 'label' => 'Chave PIX (recebimento / DICT)', 'type' => 'text'],
            ['key' => 'cashin_client_id', 'label' => 'Cash-in — Client ID', 'type' => 'text'],
            ['key' => 'cashin_client_secret', 'label' => 'Cash-in — Client Secret', 'type' => 'password'],
            ['key' => 'cashin_mtls_crt', 'label' => 'Cash-in — Certificado (.crt)', 'type' => 'file'],
            ['key' => 'cashin_mtls_key', 'label' => 'Cash-in — Chave privada mTLS (.key / PEM)', 'type' => 'file'],
            ['key' => 'cashout_client_id', 'label' => 'Cash-out — Client ID', 'type' => 'text'],
            ['key' => 'cashout_client_secret', 'label' => 'Cash-out — Client Secret', 'type' => 'password'],
            ['key' => 'cashout_mtls_crt', 'label' => 'Cash-out — Certificado (.crt)', 'type' => 'file'],
            ['key' => 'cashout_mtls_key', 'label' => 'Cash-out — Chave privada mTLS (.key / PEM)', 'type' => 'file'],
            ['key' => 'webhook_header_name', 'label' => 'Webhook — nome do header de autenticação', 'type' => 'text'],
            ['key' => 'webhook_header_token', 'label' => 'Webhook — valor do token (header)', 'type' => 'password'],
            ['key' => 'onlyup_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true],
            ['key' => 'onlyup_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à OnlyUp (R$)', 'type' => 'text', 'optional' => true],
            ['key' => 'onlyup_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à OnlyUp (R$)', 'type' => 'text', 'optional' => true],
        ],
    ]);
};
