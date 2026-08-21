<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core gateways (slug => definition).
    | Plugins may register additional gateways via GatewayRegistry::register().
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'cajupay' => [
            'slug' => 'cajupay',
            'name' => 'CajuPay',
            'image' => 'images/gateways/cajupay.png',
            'methods' => ['pix', 'card'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://cajupay.com.br',
            'driver' => \App\Gateways\CajuPay\CajuPayDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Chave pública', 'type' => 'text'],
                ['key' => 'secret_key', 'label' => 'Chave secreta', 'type' => 'password'],
                ['key' => 'checkout_webhook_signing_secret', 'label' => 'Signing secret do webhook (cwhsec_… — checkout cartão/wallets no painel CajuPay)', 'type' => 'password', 'optional' => true],
                ['key' => 'cajupay_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'cajupay_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à CajuPay (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'cajupay_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à CajuPay (R$)', 'type' => 'text', 'optional' => true],
            ],
        ],
        'spacepag' => [
            'slug' => 'spacepag',
            'name' => 'Spacepag',
            'image' => 'images/gateways/spacepag2.png',
            'methods' => ['pix'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://hub.spacepag.com.br/auth/jwt/sign-up?ref=4a5d0212320748719ee818cffdb93248',
            'driver' => \App\Gateways\Spacepag\SpacepagDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Chave pública', 'type' => 'text'],
                ['key' => 'secret_key', 'label' => 'Chave secreta', 'type' => 'password'],
                ['key' => 'spacepag_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'spacepag_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à Spacepag (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'spacepag_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à Spacepag (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'webhook_postback_base_url', 'label' => 'URL base pública para webhooks (HTTPS, sem barra final)', 'type' => 'text', 'optional' => true],
            ],
        ],
        'bspay' => [
            'slug' => 'bspay',
            'name' => 'BSPay',
            'image' => 'images/gateways/bspay.png',
            'methods' => ['pix'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil, México',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'mexico.png', 'name' => 'México'],
            ],
            'signup_url' => 'https://bspay.co',
            'driver' => \App\Gateways\Bspay\BspayDriver::class,
            'credential_keys' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                ['key' => 'bspay_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'bspay_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à BSPay (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'bspay_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à BSPay (R$)', 'type' => 'text', 'optional' => true],
            ],
        ],
        'woovi' => [
            'slug' => 'woovi',
            'name' => 'Woovi',
            'image' => 'images/gateways/woovi.png',
            'methods' => ['pix'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://woovi.com',
            'driver' => \App\Gateways\Woovi\WooviDriver::class,
            'credential_keys' => [
                ['key' => 'app_id', 'label' => 'AppID (API)', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Sandbox — ambiente de testes (API: api.woovi-sandbox.com)', 'type' => 'boolean'],
                ['key' => 'from_pix_key', 'label' => 'Chave PIX de origem (conta Woovi — saques)', 'type' => 'text'],
                ['key' => 'woovi_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'woovi_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à Woovi (R$)', 'type' => 'text', 'optional' => true],
                ['key' => 'woovi_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à Woovi (R$)', 'type' => 'text', 'optional' => true],
            ],
        ],
        'efi' => [
            'slug' => 'efi',
            'name' => 'Efí',
            'image' => 'images/gateways/efi.png',
            'methods' => ['pix', 'card', 'boleto', 'pix_auto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://sejaefi.com.br',
            'driver' => \App\Gateways\Efi\EfiDriver::class,
            'certificate_key' => 'certificate',
            'credential_keys' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                ['key' => 'pix_key', 'label' => 'Chave PIX (E‑mail, CPF, CNPJ ou aleatória)', 'type' => 'text'],
                ['key' => 'payee_code', 'label' => 'Identificador de conta (payee_code) — para cartão', 'type' => 'text'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
                ['key' => 'certificate', 'label' => 'Certificado P12', 'type' => 'file'],
            ],
        ],
        'stripe' => [
            'slug' => 'stripe',
            'name' => 'Stripe',
            'image' => 'images/gateways/stripe.png',
            'methods' => ['card'],
            'scope' => 'international',
            'country_flag' => 'global.png',
            'country_name' => 'Global',
            'signup_url' => 'https://dashboard.stripe.com/register',
            'driver' => \App\Gateways\Stripe\StripeDriver::class,
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret (whsec_...)', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de teste', 'type' => 'boolean'],
                ['key' => 'link_enabled', 'label' => 'Habilitar Stripe Link no checkout', 'type' => 'boolean'],
            ],
        ],
        'mercadopago' => [
            'slug' => 'mercadopago',
            'name' => 'Mercado Pago',
            'image' => 'images/gateways/mercado-pago.webp',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'international',
            'country' => 'br',
            'country_name' => 'Brasil, Argentina, Chile, Colômbia, México, Peru, Uruguai',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'argentina.png', 'name' => 'Argentina'],
                ['flag' => 'chile.png', 'name' => 'Chile'],
                ['flag' => 'colombia.png', 'name' => 'Colômbia'],
                ['flag' => 'mexico.png', 'name' => 'México'],
                ['flag' => 'peru.png', 'name' => 'Peru'],
                ['flag' => 'uruguay.png', 'name' => 'Uruguai'],
            ],
            'signup_url' => 'https://www.mercadopago.com.br/developers',
            'driver' => \App\Gateways\MercadoPago\MercadoPagoDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar sandbox (credenciais de teste)', 'type' => 'boolean'],
            ],
        ],
        'pushinpay' => [
            'slug' => 'pushinpay',
            'name' => 'Pushin Pay',
            'image' => 'images/gateways/pushinpay.png',
            'methods' => ['pix', 'pix_auto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://app.pushinpay.com.br/register',
            'driver' => \App\Gateways\PushinPay\PushinPayDriver::class,
            'credential_keys' => [
                ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
            ],
        ],
        'asaas' => [
            'slug' => 'asaas',
            'name' => 'Asaas',
            'image' => 'images/gateways/asaas.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://www.asaas.com',
            'driver' => \App\Gateways\Asaas\AsaasDriver::class,
            'credential_keys' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
            ],
        ],
        'pagarme' => [
            'slug' => 'pagarme',
            'name' => 'Pagar.me',
            'image' => 'images/gateways/pagarme.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://pagar.me',
            'driver' => \App\Gateways\Pagarme\PagarmeDriver::class,
            'checkout_payload_keys' => ['public_key'],
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'sandbox', 'label' => 'Sandbox', 'type' => 'boolean'],
            ],
        ],
        'linaopenx' => [
            'slug' => 'linaopenx',
            'name' => 'Lina OpenX',
            'image' => 'images/gateways/lina.jpg',
            /** Sem o plugin `linaopenx` instalado/ativo: card no Financeiro + modal; config e checkout bloqueados. */
            'requires_plugin' => 'linaopenx',
            'methods' => ['open_finance'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://linaopenx.com.br',
            'driver' => \App\Gateways\LinaOpenx\LinaOpenxDriver::class,
            'credential_keys' => [
                ['key' => 'client_id', 'label' => 'Client ID (OAuth)', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret (OAuth)', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Homologação (HML): só se as credenciais forem do IAM hml.linaob.com.br', 'type' => 'boolean'],
                ['key' => 'sub_tenant_id', 'label' => 'Sub-tenant ID (opcional, ex.: newpay ou UUID)', 'type' => 'text', 'optional' => true],
                ['key' => 'token_url', 'label' => 'URL do token OAuth (opcional)', 'type' => 'text', 'optional' => true],
                ['key' => 'api_base_url', 'label' => 'URL base da API Embedded Payment Manager (opcional)', 'type' => 'text', 'optional' => true],
                ['key' => 'webhook_secret', 'label' => 'Segredo do webhook (HMAC, opcional — painel Lina)', 'type' => 'password', 'optional' => true],
                // Credor (recebedor): obrigatório na API white-label Lina para criar pagamento
                ['key' => 'creditor_name', 'label' => 'Credor: nome (obrigatório para checkout Open Finance)', 'type' => 'text'],
                ['key' => 'creditor_cpf_cnpj', 'label' => 'Credor: CPF/CNPJ (obrigatório)', 'type' => 'text'],
                ['key' => 'creditor_ispb', 'label' => 'Credor: ISPB do banco (8 dígitos, obrigatório)', 'type' => 'text'],
                ['key' => 'creditor_issuer', 'label' => 'Credor: agência (1 a 4 dígitos, obrigatório)', 'type' => 'text'],
                ['key' => 'creditor_number', 'label' => 'Credor: número da conta (sem dígito ou com, só números, obrigatório)', 'type' => 'text'],
                ['key' => 'creditor_account_type', 'label' => 'Credor: tipo de conta: CACC (corrente), SVGS (poupança) ou TRAN', 'type' => 'text', 'optional' => true],
            ],
        ],
         /*
         | Versell — uma adquirente na UI; credenciais internas cash_in / cash_out.
         | Cash In (PIX cob + webhook) e Cash Out (dict + transfer/cashout).
         */
        'versell' => [
            'slug' => 'versell',
            'name' => 'Versell',
            'image' => 'images/gateways/versell-logo.svg',
            'methods' => ['pix', 'pix_auto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://finance.versell.com.br',
            'driver' => \App\Gateways\Versell\VersellDriver::class,
            'credential_keys' => [
                ['key' => 'cash_in_client_id', 'label' => 'Client ID', 'type' => 'text', 'group' => 'cash_in', 'group_label' => 'Versell — Cash In'],
                ['key' => 'cash_in_client_secret', 'label' => 'Client Secret', 'type' => 'password', 'group' => 'cash_in', 'group_label' => 'Versell — Cash In', 'optional' => true],
                ['key' => 'cash_in_pix_key', 'label' => 'Chave PIX', 'type' => 'text', 'group' => 'cash_in', 'group_label' => 'Versell — Cash In'],
                ['key' => 'cash_in_certificate', 'label' => 'Certificado CRT', 'type' => 'file', 'accept' => '.crt,.pem', 'group' => 'cash_in', 'group_label' => 'Versell — Cash In', 'file_kind' => 'certificate'],
                ['key' => 'cash_in_private_key', 'label' => 'Private Key KEY', 'type' => 'file', 'accept' => '.key,.pem', 'group' => 'cash_in', 'group_label' => 'Versell — Cash In', 'file_kind' => 'private_key'],
                ['key' => 'cash_out_client_id', 'label' => 'Client ID', 'type' => 'text', 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out'],
                ['key' => 'cash_out_client_secret', 'label' => 'Client Secret', 'type' => 'password', 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out', 'optional' => true],
                ['key' => 'cash_out_certificate', 'label' => 'Certificado CRT', 'type' => 'file', 'accept' => '.crt,.pem', 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out', 'file_kind' => 'certificate'],
                ['key' => 'cash_out_private_key', 'label' => 'Private Key KEY', 'type' => 'file', 'accept' => '.key,.pem', 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out', 'file_kind' => 'private_key'],
                ['key' => 'versell_payout_min_brl', 'label' => 'Mínimo líquido de payout (R$)', 'type' => 'text', 'optional' => true, 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out'],
                ['key' => 'versell_admin_fee_pix_brl', 'label' => 'Taxa PIX paga à Versell (R$)', 'type' => 'text', 'optional' => true, 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out'],
                ['key' => 'versell_admin_fee_payout_brl', 'label' => 'Taxa de saque paga à Versell (R$)', 'type' => 'text', 'optional' => true, 'group' => 'cash_out', 'group_label' => 'Versell — Cash Out'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Adquirentes excluídas da ApiPix (integração do infoprodutor).
    | Checkout, PixGO e demais canais continuam podendo usá-las.
    |--------------------------------------------------------------------------
    */
    'api_pix_excluded_slugs' => [
        'bspay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Adquirentes (painel plataforma, checkout, API): apenas estes slugs são
    | oferecidos na UI e na ordem de pagamento. Outras entradas em config
    | permanecem para compatibilidade legada até migração.
    |--------------------------------------------------------------------------
    */
    'platform_acquirer_slugs' => [
        'cajupay',
        'efi',
        // 'spacepag', // oculto da UI (Financeiro → Adquirentes) e da ordem de cobrança
        'woovi',
        'bspay',
        'onlyup',
        'mercadopago',
        'pagarme',
        'stripe',
        'linaopenx',
        'versell', // Cash In + Cash Out (dict)
    ],

    /*
    |--------------------------------------------------------------------------
    | Default redundancy order per method (when tenant has not configured).
    |--------------------------------------------------------------------------
    */
    'default_order' => [
        'pix' => ['cajupay', /* 'spacepag', */ 'woovi', 'bspay', 'onlyup', 'efi', 'mercadopago', 'pagarme', 'pushinpay', 'asaas', 'versell'],
        'card' => ['cajupay', 'efi', 'stripe', 'mercadopago', 'pagarme', 'asaas'],
        'boleto' => ['efi', 'mercadopago', 'pagarme', 'asaas'],
        'pix_auto' => ['efi', 'pushinpay', 'versell'],
        'open_finance' => ['linaopenx'],
        'crypto' => [],
    ],
];
