<?php

use App\Support\VapidEnvKeys;

$versionFile = base_path('VERSION');
$version = trim((is_file($versionFile) ? file_get_contents($versionFile) : '') ?: '') ?: env('GETFY_VERSION', '1.0.0');

$cloudModeEnv = filter_var(env('GETFY_CLOUD', false), FILTER_VALIDATE_BOOLEAN);
$cloudModeFolder = is_dir(base_path('cloud'));

return [
    'installed' => is_file(base_path('.env')) && filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN),
    'cloud_mode' => $cloudModeEnv || $cloudModeFolder,
    'cloud' => [
        'orch_api_base_url' => rtrim((string) env('ORCH_API_BASE_URL', 'https://orch.getfy.cloud'), '/'),
        'billing_cache_minutes' => (int) env('GETFY_CLOUD_BILLING_CACHE_MINUTES', 10),
        'billing_renew_window_days' => (int) env('GETFY_CLOUD_BILLING_RENEW_WINDOW_DAYS', 7),
    ],
    'auto_migrate' => filter_var(env('APP_AUTO_MIGRATE', false), FILTER_VALIDATE_BOOLEAN),
    'cron_secret' => env('CRON_SECRET', null),
    'version' => $version,
    'update_repository_url' => env('GETFY_UPDATE_REPO', 'https://github.com/getfy-opensource/getfy.git'),
    'update_branch' => env('GETFY_UPDATE_BRANCH', 'main'),
    'updates_enabled' => env('GETFY_UPDATES_ENABLED', true),
    'php_path' => env('GETFY_PHP_PATH', null),
    'pwa' => [
        'push_provider' => env('PWA_PUSH_PROVIDER', 'vapid'),
        'vapid_public' => VapidEnvKeys::normalize(env('PWA_VAPID_PUBLIC')),
        'vapid_private' => VapidEnvKeys::normalize(env('PWA_VAPID_PRIVATE')),
        'firebase_project_id' => env('FIREBASE_PROJECT_ID'),
        'firebase_api_key' => env('FIREBASE_API_KEY'),
        'firebase_messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'firebase_app_id' => env('FIREBASE_APP_ID'),
        'firebase_web_vapid_key' => env('FIREBASE_WEB_VAPID_KEY'),
        'firebase_service_account' => null,
    ],
    'app_name' => 'Getfy',
    'theme_primary' => '#00cc00',
    'app_logo' => 'https://cdn.getfy.cloud/logo-white.png',
    'app_logo_dark' => 'https://cdn.getfy.cloud/logo-dark.png',
    'app_logo_icon' => 'https://cdn.getfy.cloud/collapsed-logo.png',
    'app_logo_icon_dark' => 'https://cdn.getfy.cloud/collapsed-logo.png',

    /** White Label plugin (null = default / não aplicado) */
    'login_hero_image' => null,
    'login_hero_tagline' => 'Sua plataforma para vender mais.',
    'login_hero_subtagline' => 'Feita para quem escala de verdade.',
    'favicon_url' => null,
    'pwa_theme_color' => null,
    'pwa_icon_192' => null,
    'pwa_icon_512' => null,

    /*
    | Segurança operador da plataforma (roadmap): 2FA TOTP obrigatório para platform_admin;
    | allowlist de IP via env; sessão já regenerada no login (/login e /plataforma/login).
    */

    /**
     * URL pública base (HTTPS) para montar postbacks da Spacepag quando APP_URL é local ou HTTP.
     * Ex.: https://api.sualoja.com — o path /webhooks/gateways/spacepag é acrescentado automaticamente.
     */
    'webhook_public_url' => is_string($v = env('GETFY_WEBHOOK_PUBLIC_URL')) && trim($v) !== ''
        ? rtrim(trim($v), '/')
        : null,

    /**
     * Assinaturas: após N dias corridos desde o fim do período (current_period_end) em atraso (past_due),
     * o comando subscriptions:expire-due marca como cancelled e dispara webhook assinatura_cancelada.
     * Use 0 para cancelar no mesmo dia em que entra em past_due (não recomendado).
     */
    'subscriptions' => [
        'cancel_grace_days_after_period_end' => max(0, (int) env('GETFY_SUBSCRIPTION_CANCEL_GRACE_DAYS', 14)),
    ],

    /**
     * Anti-flood no checkout público (rate limit + regras no CheckoutAbuseGuard).
     */
    'installer' => [
        'enabled' => filter_var(env('INSTALLER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'token' => is_string($t = env('INSTALLER_TOKEN')) && trim($t) !== ''
            ? trim($t)
            : (is_file($installTokenFile = base_path('.install-token'))
                ? trim((string) file_get_contents($installTokenFile))
                : null),
    ],

    /**
     * Modo demonstração: interruptor mestre via .env (GETFY_DEMO_MODE=true).
     * Com demo ativo: read-only global, login rápido, dados fictícios no painel operador.
     * Para desativar: GETFY_DEMO_MODE=false + php artisan config:clear
     */
    'demo_mode' => filter_var(env('GETFY_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN),
    'demo_admin_email' => is_string($v = env('GETFY_DEMO_ADMIN_EMAIL')) && trim($v) !== '' ? strtolower(trim($v)) : null,
    'demo_seller_email' => is_string($v = env('GETFY_DEMO_SELLER_EMAIL')) && trim($v) !== '' ? strtolower(trim($v)) : null,

    /**
     * Ferramenta oculta: /plataforma/ops/mercadopago-saldo (requer platform_admin).
     */
    'mp_balance_tool' => [
        'enabled' => filter_var(env('GETFY_MP_BALANCE_TOOL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * API PIX / saques (integradores e escala).
     */
    'api' => [
        'inbound_webhooks_async' => filter_var(env('API_INBOUND_WEBHOOKS_ASYNC', true), FILTER_VALIDATE_BOOLEAN),
        'rate_limits' => [
            'legacy' => max(60, (int) env('API_RATE_LEGACY_PER_MINUTE', 120)),
            'standard' => max(60, (int) env('API_RATE_STANDARD_PER_MINUTE', 600)),
            'withdrawals_write' => max(5, (int) env('API_RATE_WITHDRAWALS_PER_MINUTE', 30)),
        ],
        'withdrawals' => [
            'max_per_day' => max(1, (int) env('API_WITHDRAWALS_MAX_PER_DAY', 50)),
            'max_amount_per_day' => max(1000, (float) env('API_WITHDRAWALS_MAX_AMOUNT_PER_DAY', 500000)),
        ],
    ],

    'checkout_embed' => [
        /** Permite embed do checkout público em iframe em sites externos (frame-ancestors *). */
        'enabled' => filter_var(env('CHECKOUT_IFRAME_EMBED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'checkout_security' => [
        'min_seconds_before_pay' => max(0, (int) env('CHECKOUT_MIN_SECONDS_BEFORE_PAY', 2)),
        'duplicate_pending_minutes' => max(1, (int) env('CHECKOUT_DUPLICATE_PENDING_MINUTES', 15)),
        'max_pending_per_email' => max(1, (int) env('CHECKOUT_MAX_PENDING_PER_EMAIL', 3)),
        'session_max_age_hours' => max(1, (int) env('CHECKOUT_SESSION_MAX_AGE_HOURS', 2)),
        'server_idempotency_ttl_seconds' => max(30, (int) env('CHECKOUT_SERVER_IDEMPOTENCY_TTL', 120)),
        'rate_limits' => [
            /** Boleto, pix_auto e demais métodos (exceto pix/cartão/wallets). */
            'pay_per_minute' => max(1, (int) env('CHECKOUT_RATE_PAY_PER_MINUTE', 20)),
            /** Máx. de PIX gerados por minuto por IP (POST /checkout, payment_method=pix). */
            'pix_per_minute' => max(1, (int) env('CHECKOUT_RATE_PIX_PER_MINUTE', 5)),
            /** Máx. de PIX por e-mail a cada 10 minutos (anti-abuso por conta). */
            'pix_email_per_ten_minutes' => max(1, (int) env('CHECKOUT_RATE_PIX_EMAIL_PER_TEN_MINUTES', 5)),
            /** Cartão / Apple Pay / Google Pay no POST /checkout. */
            'card_per_minute' => max(1, (int) env('CHECKOUT_RATE_CARD_PER_MINUTE', 15)),
            /** Explorar métodos CajuPay (trocar cartão/wallet) — não consome limite de PIX. */
            'cajupay_session_per_minute' => max(1, (int) env('CHECKOUT_RATE_CAJUPAY_SESSION_PER_MINUTE', 30)),
            /** Materializar pedido antes do confirm do SDK CajuPay. */
            'cajupay_confirm_per_minute' => max(1, (int) env('CHECKOUT_RATE_CAJUPAY_CONFIRM_PER_MINUTE', 15)),
            'track_per_minute' => max(1, (int) env('CHECKOUT_RATE_TRACK_PER_MINUTE', 30)),
            'coupon_per_minute' => max(1, (int) env('CHECKOUT_RATE_COUPON_PER_MINUTE', 20)),
            'shipping_quote_per_minute' => max(1, (int) env('CHECKOUT_RATE_SHIPPING_QUOTE_PER_MINUTE', 30)),
        ],
    ],

    'stacker' => [
        'api_url' => rtrim((string) env('STACKER_API_URL', 'https://api.stacker.builders'), '/'),
        'agent_token' => env('STACKER_AGENT_TOKEN'),
        'signing_key' => env('STACKER_RELEASE_SIGNING_KEY'),
        'license_disabled' => app()->environment('local')
            && filter_var(env('STACKER_LICENSE_DISABLED', false), FILTER_VALIDATE_BOOLEAN),
        'support_whatsapp' => env('STACKER_SUPPORT_WHATSAPP'),
    ],
];
