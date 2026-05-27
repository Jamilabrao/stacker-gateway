<?php

namespace App\Services;

use App\Mail\AccessGrantedMail;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AccessEmailSendResult;
use App\Support\EmailLogoHtml;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class AccessEmailService
{
    public function __construct(
        protected TenantMailConfigService $mailConfig,
        protected MemberAreaResolver $memberAreaResolver,
    ) {}

    public function sendForOrder(Order $order, bool $force = false): AccessEmailSendResult
    {
        Log::info('AccessEmailService: tentando enviar e-mail de acesso.', ['order_id' => $order->id]);

        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) {
            Log::warning('AccessEmailService: e-mail não enviado — pedido sem produto.', ['order_id' => $order->id]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_NO_PRODUCT,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_NO_PRODUCT)
            );
        }

        $productType = $product->type;

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            Log::info('AccessEmailService: produto área de membros, resolvendo link e senha.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
            ]);
        }

        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            Log::info('AccessEmailService: e-mail não enviado — produto é tipo link de pagamento.', [
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_type' => $productType,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_LINK_PAGAMENTO,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_LINK_PAGAMENTO)
            );
        }

        if ($product->type === Product::TYPE_PRODUTO_FISICO) {
            return $this->sendPhysicalProductConfirmationEmail($order, $product, $force);
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        if ($bodyHtml === '') {
            $bodyHtml = (string) (Product::defaultEmailTemplate()['body_html'] ?? '');
        }

        $customerEmail = $order->email ?: $order->user?->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            Log::warning('AccessEmailService: e-mail não enviado — sem e-mail válido para o pedido.', [
                'order_id' => $order->id,
                'product_type' => $productType,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $order->user?->name ?? explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $this->resolveAccessLinkForProduct($product, $order->user);

        if (config('app.debug') && $product->type === Product::TYPE_AREA_MEMBROS) {
            Log::debug('AccessEmailService: link_acesso', ['order_id' => $order->id, 'link' => $linkAcesso]);
        }

        $senha = '';
        $passwordCacheKey = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS && $order->user_id && $order->product_id) {
            $passwordCacheKey = 'access_password.'.$order->user_id.'.'.$order->product_id;
            $decrypted = null;
            $meta = $order->metadata ?? [];
            if (! empty($meta['access_password_temp'])) {
                try {
                    $decrypted = decrypt($meta['access_password_temp']);
                } catch (\Throwable $e) {
                    // ignora erro de decrypt
                }
            }
            if (is_string($decrypted) && $decrypted !== '') {
                $senha = $decrypted;
            } else {
                $cached = Cache::get($passwordCacheKey);
                if (is_string($cached) && $cached !== '') {
                    $senha = $cached;
                }
            }
            Log::info('AccessEmailService: área de membros — senha (metadata ou cache).', [
                'order_id' => $order->id,
                'senha_from_metadata' => isset($meta['access_password_temp']),
                'senha_encontrada' => $senha !== '',
            ]);
        }

        $tenantIdForMail = $order->tenant_id ?? $product->tenant_id;
        $isRenewal = (bool) $order->is_renewal;

        $cacheKey = 'access_email_sent.'.$order->id;
        $cacheTtl = $isRenewal ? now()->addHours(24) : now()->addHours(1);
        if (! $force && Cache::has($cacheKey)) {
            Log::info('AccessEmailService: e-mail já enviado anteriormente (cache).', [
                'order_id' => $order->id,
                'tenant_id_for_mail' => $tenantIdForMail,
            ]);

            return AccessEmailSendResult::ok();
        }

        if ($isRenewal) {
            $subject = 'Renovação confirmada — '.$product->name;
            $bodyHtml = $this->buildRenewalSuccessBody($customerName, $product->name);
        } elseif ($product->type === Product::TYPE_AREA_MEMBROS_EXTERNA) {
            $subject = 'Compra confirmada — '.$product->name;
            $bodyHtml = $this->buildExternalMemberAreaPendingBody($customerName, $product->name);
        } else {
            $bodyHtmlBeforeReplace = $bodyHtml;
            $replace = [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => $product->name,
                '{link_acesso}' => $linkAcesso,
                '{email_cliente}' => $customerEmail,
                '{senha}' => $senha,
            ];
            $subject = str_replace(array_keys($replace), array_values($replace), $subject);
            $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);
            $brandingLogo = BrandingEmailData::forTenant($tenantIdForMail)['logo_url'] ?? null;
            if (is_string($brandingLogo) && $brandingLogo !== '') {
                $bodyHtml = $this->prependLogoToBody($brandingLogo, $bodyHtml);
            }
            if ($product->type === Product::TYPE_AREA_MEMBROS
                && $senha !== ''
                && ! str_contains($bodyHtmlBeforeReplace, '{senha}')
            ) {
                $bodyHtml = $this->appendMemberAreaPasswordCredentialsBlock($bodyHtml, $customerEmail, $senha);
            }
        }

        $sendResult = $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $tenantIdForMail, $template);
        if (! $sendResult->success) {
            return $sendResult;
        }

        Cache::put($cacheKey, true, $cacheTtl);

        Log::info($isRenewal ? 'AccessEmailService: e-mail de renovação enviado.' : 'AccessEmailService: e-mail de acesso enviado.', [
            'order_id' => $order->id,
            'product_type' => $productType,
            'tenant_id_for_mail' => $tenantIdForMail,
            'to' => $customerEmail,
        ]);

        if ($passwordCacheKey !== null) {
            Cache::forget($passwordCacheKey);
        }

        $meta = $order->metadata ?? [];
        if (! empty($meta['access_password_temp'])) {
            unset($meta['access_password_temp']);
            $order->update(['metadata' => $meta]);
        }

        return AccessEmailSendResult::ok();
    }

    /**
     * Tenta SMTP do tenant (se configurado) e depois SMTP global da plataforma.
     */
    private function sendAccessMailableWithFallback(
        string $subject,
        string $bodyHtml,
        string $customerEmail,
        ?int $tenantIdForMail,
        array $template
    ): AccessEmailSendResult {
        $attempts = [];

        if ($tenantIdForMail !== null && $this->mailConfig->isEmailConfigured($tenantIdForMail)) {
            $attempts[] = [
                'label' => 'smtp_tenant',
                'apply' => function () use ($tenantIdForMail): void {
                    $this->mailConfig->applyMailerConfigForTenant($tenantIdForMail, [], null);
                },
            ];
        }

        if ($this->mailConfig->isEmailConfigured(null)) {
            $attempts[] = [
                'label' => 'smtp_plataforma_global',
                'apply' => function (): void {
                    $this->mailConfig->applyPlatformGlobalMailerConfig();
                },
            ];
        }

        if ($attempts === []) {
            Log::warning('AccessEmailService: nenhum SMTP configurado.', [
                'tenant_id_for_mail' => $tenantIdForMail,
            ]);

            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_SMTP_NOT_CONFIGURED)
            );
        }

        $resolveFrom = function () use ($template) {
            $fromAddress = config('mail.from.address');
            $fromName = ! empty($template['from_name']) ? $template['from_name'] : (config('mail.from.name') ?? '');

            return [$fromAddress, $fromName];
        };

        $lastError = null;

        foreach ($attempts as $attempt) {
            try {
                $attempt['apply']();
                $this->mailConfig->assertSmtpHostIsConfigured();
                Mail::purge('smtp');
                [$fromAddress, $fromName] = $resolveFrom();
                Log::info('AccessEmailService: enviando.', [
                    'via' => $attempt['label'],
                    'tenant_id_for_mail' => $tenantIdForMail,
                    'provider' => $this->mailConfig->getProviderForTenant(
                        $attempt['label'] === 'smtp_plataforma_global' ? null : $tenantIdForMail
                    ),
                    'host' => config('mail.mailers.smtp.host'),
                    'from' => $fromAddress,
                    'from_name' => $fromName,
                ]);
                $mailable = new AccessGrantedMail($subject, $bodyHtml);
                $mailable->from($fromAddress, $fromName);
                Mail::mailer('smtp')->to($customerEmail)->send($mailable);

                return AccessEmailSendResult::ok();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('AccessEmailService: tentativa de envio falhou.', [
                    'via' => $attempt['label'],
                    'order_tenant' => $tenantIdForMail,
                    'message' => $lastError,
                ]);
            }
        }

        return AccessEmailSendResult::fail(
            AccessEmailSendResult::REASON_SMTP_SEND_FAILED,
            AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_SMTP_SEND_FAILED, $lastError)
        );
    }

    /**
     * Return the access link for an order (same link used in the access email).
     */
    public function getAccessLinkForOrder(Order $order): string
    {
        $order->loadMissing(['product', 'user']);
        $product = $order->product;
        if (! $product) {
            return '';
        }

        return $this->resolveAccessLinkForProduct($product, $order->user);
    }

    /**
     * Link usado no e-mail de acesso e na página de obrigado.
     */
    public function resolveAccessLinkForProduct(Product $product, ?User $user = null): string
    {
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            if ($user instanceof User) {
                return $this->resolveMemberAreaMagicLink($product, $user);
            }

            return $this->resolvePlatformLoginLink();
        }

        return $this->resolveLinkAcesso($product);
    }

    public function sendForUserProduct(User $user, Product $product): AccessEmailSendResult
    {
        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_LINK_PAGAMENTO,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_LINK_PAGAMENTO)
            );
        }

        $config = $product->checkout_config ?? [];
        $template = array_merge(Product::defaultEmailTemplate(), $config['email_template'] ?? []);
        $subject = (string) ($template['subject'] ?? 'Seu acesso');
        $bodyHtml = (string) ($template['body_html'] ?? '');

        if ($bodyHtml === '') {
            $bodyHtml = (string) (Product::defaultEmailTemplate()['body_html'] ?? '');
        }

        if ($bodyHtml === '') {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_EMPTY_TEMPLATE,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_EMPTY_TEMPLATE)
            );
        }

        $customerEmail = $user->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $user->name ?: explode('@', $customerEmail)[0] ?? 'Cliente';
        $linkAcesso = $this->resolveAccessLinkForProduct($product, $user);

        $replace = [
            '{nome_cliente}' => $customerName,
            '{nome_produto}' => $product->name,
            '{link_acesso}' => $linkAcesso,
            '{email_cliente}' => $customerEmail,
            '{senha}' => '',
        ];
        $subject = str_replace(array_keys($replace), array_values($replace), $subject);
        $bodyHtml = str_replace(array_keys($replace), array_values($replace), $bodyHtml);

        $brandingLogo = BrandingEmailData::forTenant($product->tenant_id)['logo_url'] ?? null;
        if (is_string($brandingLogo) && $brandingLogo !== '') {
            $bodyHtml = $this->prependLogoToBody($brandingLogo, $bodyHtml);
        } elseif (! empty($template['logo_url'])) {
            $bodyHtml = $this->prependLogoToBody($template['logo_url'], $bodyHtml);
        }

        return $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $product->tenant_id, $template);
    }

    private function resolveLinkAcesso(Product $product): string
    {
        if ($product->type === Product::TYPE_LINK) {
            $config = $product->checkout_config ?? [];
            $link = $config['deliverable_link'] ?? '';

            return is_string($link) ? $link : '';
        }

        return '';
    }

    private function resolveMemberAreaMagicLink(Product $product, User $user): string
    {
        $base = $this->memberAreaResolver->baseUrlForProduct($product);
        $expiresAt = now()->addDays(7);
        $appUrl = rtrim((string) config('app.url'), '/');
        $appScheme = parse_url($appUrl, PHP_URL_SCHEME) ?: null;

        $useHostAccess = true;
        $path = parse_url($base, PHP_URL_PATH);
        if (is_string($path) && str_starts_with(trim($path, '/'), 'm/')) {
            $useHostAccess = false;
        }

        $slugForSignedPathAccess = null;
        if (! $useHostAccess) {
            $basePath = parse_url($base, PHP_URL_PATH);
            if (is_string($basePath) && $basePath !== '') {
                $segments = explode('/', trim($basePath, '/'));
                if (($segments[0] ?? null) === 'm' && ! empty($segments[1])) {
                    $slugForSignedPathAccess = (string) $segments[1];
                }
            }
            if ($slugForSignedPathAccess === null || $slugForSignedPathAccess === '') {
                $slugForSignedPathAccess = (string) ($product->checkout_slug ?? '');
            }
        }

        $originalRoot = $appUrl;
        $originalScheme = $appScheme;

        try {
            if ($useHostAccess) {
                $scheme = parse_url($base, PHP_URL_SCHEME);
                if (is_string($scheme) && $scheme !== '') {
                    URL::forceScheme($scheme);
                }
                URL::forceRootUrl(rtrim($base, '/'));

                return URL::temporarySignedRoute('member-area.magic-access.host', $expiresAt, [
                    'u' => $user->id,
                    'p' => $product->id,
                ]);
            }

            return URL::temporarySignedRoute('member-area.magic-access', $expiresAt, [
                'slug' => $slugForSignedPathAccess,
                'u' => $user->id,
                'p' => $product->id,
            ]);
        } finally {
            URL::forceRootUrl($originalRoot);
            if (is_string($originalScheme) && $originalScheme !== '') {
                URL::forceScheme($originalScheme);
            }
        }
    }

    private function resolvePlatformLoginLink(): string
    {
        return url('/login');
    }

    private function prependLogoToBody(string $logoUrl, string $bodyHtml): string
    {
        if (str_contains($bodyHtml, 'data-email-logo="1"')) {
            return $bodyHtml;
        }

        return EmailLogoHtml::wrap($logoUrl).$bodyHtml;
    }

    private function appendMemberAreaPasswordCredentialsBlock(string $bodyHtml, string $email, string $password): string
    {
        $block = '<div style="margin:24px 0 0;padding:20px;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;">'
            .'<p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#92400e;"><strong>Guarde seus dados de acesso</strong></p>'
            .'<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#78350f;">O botão de acesso acima entra automaticamente na sua conta. Se você sair ou usar outro aparelho, faça login na área de membros com:</p>'
            .'<p style="margin:0 0 10px;font-size:14px;color:#0f172a;"><strong>E-mail:</strong> '.e($email).'</p>'
            .'<p style="margin:0;font-size:15px;color:#0f172a;font-family:Consolas,\'Courier New\',monospace;font-weight:600;letter-spacing:0.02em;word-break:break-all;"><strong>Senha:</strong> '.e($password).'</p>'
            .'</div>';

        return $bodyHtml.$block;
    }

    private function buildRenewalSuccessBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Sua renovação da assinatura de <strong>'.e($productName).'</strong> foi confirmada com sucesso.</p><p style="margin:0;font-size:16px;line-height:1.6;color:#334155;">Você continua com acesso total ao conteúdo. Não é necessário fazer nada.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }

    private function buildExternalMemberAreaPendingBody(string $customerName, string $productName): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Seu pagamento de <strong>'.e($productName).'</strong> foi confirmado.</p><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Este produto é entregue em uma <strong>área de membros externa</strong>. Em instantes você receberá o acesso.</p><p style="margin:0;font-size:14px;line-height:1.6;color:#64748b;">Se você não receber o acesso em alguns minutos, entre em contato com o suporte do vendedor.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }

    private function sendPhysicalProductConfirmationEmail(Order $order, Product $product, bool $force): AccessEmailSendResult
    {
        $customerEmail = $order->email ?: $order->user?->email;
        if (! $customerEmail || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return AccessEmailSendResult::fail(
                AccessEmailSendResult::REASON_INVALID_EMAIL,
                AccessEmailSendResult::messageForReason(AccessEmailSendResult::REASON_INVALID_EMAIL)
            );
        }

        $customerName = $order->user?->name ?? explode('@', $customerEmail)[0] ?? 'Cliente';
        $tenantIdForMail = $order->tenant_id ?? $product->tenant_id;
        $cacheKey = 'access_email_sent.'.$order->id;
        if (! $force && Cache::has($cacheKey)) {
            return AccessEmailSendResult::ok();
        }

        $subject = 'Pedido confirmado — '.$product->name;
        $bodyHtml = $this->buildPhysicalProductConfirmationBody($order, $customerName, $product->name);
        $brandingLogo = BrandingEmailData::forTenant($tenantIdForMail)['logo_url'] ?? null;
        if (is_string($brandingLogo) && $brandingLogo !== '') {
            $bodyHtml = $this->prependLogoToBody($brandingLogo, $bodyHtml);
        }

        $template = array_merge(Product::defaultEmailTemplate(), ($product->checkout_config ?? [])['email_template'] ?? []);

        $sendResult = $this->sendAccessMailableWithFallback($subject, $bodyHtml, $customerEmail, $tenantIdForMail, $template);
        if ($sendResult->success) {
            Cache::put($cacheKey, true, now()->addHours(1));
        }

        return $sendResult;
    }

    private function buildPhysicalProductConfirmationBody(Order $order, string $customerName, string $productName): string
    {
        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        $lines = array_filter([
            isset($addr['street'], $addr['number']) ? e($addr['street']).', '.e($addr['number']) : null,
            ! empty($addr['complement']) ? e((string) $addr['complement']) : null,
            ! empty($addr['neighborhood']) ? e((string) $addr['neighborhood']) : null,
            isset($addr['city'], $addr['state']) ? e($addr['city']).' — '.e($addr['state']) : null,
            ! empty($addr['zip']) ? 'CEP '.e((string) $addr['zip']) : null,
        ]);
        $addressBlock = $lines !== []
            ? '<p style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#334155;">'.implode('<br>', $lines).'</p>'
            : '<p style="margin:0;font-size:14px;color:#64748b;">Endereço registrado no pedido.</p>';

        $shippingAmount = (float) ($order->shipping_amount ?? 0);
        $shippingLine = $shippingAmount > 0
            ? '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>Frete:</strong> R$ '.number_format($shippingAmount, 2, ',', '.').'</p>'
            : '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>Frete:</strong> grátis</p>';

        $meta = $order->metadata ?? [];
        $deliveryHint = '';
        $min = $meta['delivery_days_min'] ?? null;
        $max = $meta['delivery_days_max'] ?? null;
        if ($min !== null) {
            $deliveryHint = '<p style="margin:0;font-size:13px;color:#64748b;">Prazo estimado: '.(int) $min
                .($max !== null && (int) $max !== (int) $min ? '–'.(int) $max : '')
                .' dias úteis após a confirmação do pagamento.</p>';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Tahoma,sans-serif;background:#f8fafc;padding:32px 24px;"><tr><td style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);"><table width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 32px 24px;text-align:center;border-bottom:1px solid #e2e8f0;"><h1 style="margin:0;font-size:22px;font-weight:600;color:#0f172a;">Olá, '.e($customerName).'!</h1></td></tr><tr><td style="padding:28px 32px;"><p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">Recebemos o pagamento do seu pedido <strong>'.e($productName).'</strong>.</p><p style="margin:0 0 12px;font-size:15px;font-weight:600;color:#0f172a;">Endereço de entrega</p>'.$addressBlock.$shippingLine.$deliveryHint.'<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#64748b;">Você receberá atualizações sobre o envio pelo e-mail informado na compra.</p></td></tr><tr><td style="padding:20px 32px;background:#f1f5f9;border-radius:0 0 12px 12px;"><p style="margin:0;font-size:13px;color:#64748b;">Qualquer dúvida, responda este e-mail.</p></td></tr></table></td></tr></table>';
    }
}
