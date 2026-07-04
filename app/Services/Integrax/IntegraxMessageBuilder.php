<?php

namespace App\Services\Integrax;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\PlatformIntegraxSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\MemberAreaResolver;
use Illuminate\Support\Facades\URL;

class IntegraxMessageBuilder
{
    public function __construct(
        private MemberAreaResolver $memberAreaResolver
    ) {}

    /**
     * @return array<string, string>
     */
    public function fromCheckoutSession(CheckoutSession $session): array
    {
        $session->loadMissing('product:id,name,checkout_slug');

        $name = trim((string) ($session->name ?? ''));
        if ($name === '' && is_string($session->email) && $session->email !== '') {
            $name = explode('@', $session->email)[0] ?? 'Cliente';
        }
        if ($name === '') {
            $name = 'Cliente';
        }

        $slug = $session->checkout_slug ?? $session->product?->checkout_slug ?? '';
        $link = $slug !== '' ? URL::route('checkout.show', ['slug' => $slug]) : '';

        return [
            'nome' => $name,
            'produto' => (string) ($session->product?->name ?? 'Produto'),
            'valor' => '',
            'link' => $link,
            'link_acesso' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fromOrder(Order $order): array
    {
        $order->loadMissing(['user', 'product']);

        $email = $order->email ?? $order->user?->email ?? '';
        $name = trim((string) ($order->user?->name ?? ''));
        if ($name === '' && is_string($email) && $email !== '') {
            $name = explode('@', $email)[0] ?? 'Cliente';
        }
        if ($name === '') {
            $name = 'Cliente';
        }

        $product = $order->product;
        $slug = $order->getCheckoutSlug();
        $link = $slug ? URL::route('checkout.show', ['slug' => $slug]) : '';

        return [
            'nome' => $name,
            'produto' => (string) ($product?->name ?? 'Produto'),
            'valor' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
            'link' => $link,
            'link_acesso' => $this->resolveAccessLink($product, $order->user),
        ];
    }

    public function shouldSendAccessGranted(Order $order): bool
    {
        $order->loadMissing('product');
        $product = $order->product;
        if (! $product) {
            return false;
        }

        if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
            return false;
        }

        return in_array($product->type, [
            Product::TYPE_AREA_MEMBROS,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
            Product::TYPE_LINK,
            Product::TYPE_APLICATIVO,
        ], true);
    }

    private function resolveAccessLink(?Product $product, ?User $user): string
    {
        if (! $product || ! $user) {
            return url('/login');
        }

        if ($product->type === Product::TYPE_LINK) {
            $config = $product->checkout_config ?? [];
            $link = $config['deliverable_link'] ?? '';

            return is_string($link) && $link !== '' ? $link : url('/login');
        }

        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            return $this->resolveMemberAreaMagicLink($product, $user);
        }

        return url('/login');
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
}
