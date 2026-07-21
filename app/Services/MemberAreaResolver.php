<?php

namespace App\Services;

use App\Models\MemberAreaDomain;
use App\Models\Product;
use App\Models\User;
use App\Support\PublicAppUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class MemberAreaResolver
{
    /**
     * Resolve product and access type from request (path, subdomain, or custom domain).
     *
     * @return array{product: Product, access_type: string, slug: string|null}|null
     */
    public function resolve(Request $request): ?array
    {
        $hostRaw = strtolower(rtrim(trim($request->getHost()), '.'));
        $hostNormalized = MemberAreaDomain::normalizeCustomHost($hostRaw);
        $hosts = array_values(array_unique(array_filter([$hostRaw, $hostNormalized])));
        $path = $request->path();

        // Custom domain: host matches a stored custom domain
        $domain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_CUSTOM)
            ->whereIn('value', $hosts)
            ->with('product')
            ->first();
        if ($domain && $domain->product && $domain->product->type === Product::TYPE_AREA_MEMBROS) {
            return [
                'product' => $domain->product,
                'access_type' => 'custom',
                'slug' => $domain->product->checkout_slug,
            ];
        }

        // Subdomain: {slug}.members.xxx
        if (config('members.subdomain_enabled')) {
            $base = config('members.subdomain_base', '');
            if ($base && str_ends_with($hostRaw, $base) && $hostRaw !== $base) {
                $prefix = str_replace('.'.$base, '', $hostRaw);
                if ($prefix !== $hostRaw) {
                    $slug = $prefix;
                    $product = Product::where('checkout_slug', $slug)
                        ->where('type', Product::TYPE_AREA_MEMBROS)
                        ->first();
                    if ($product) {
                        return [
                            'product' => $product,
                            'access_type' => 'subdomain',
                            'slug' => $slug,
                        ];
                    }
                }
            }
        }

        // Path: /m/{slug} — use route parameter when available (reliable with subdirs), else parse path
        $pathSlug = $request->route()?->parameter('slug');
        if ($pathSlug !== null && $pathSlug !== '') {
            $slugNormalized = strtolower((string) $pathSlug);
            $product = Product::where('checkout_slug', $slugNormalized)
                ->where('type', Product::TYPE_AREA_MEMBROS)
                ->first();
            if ($product) {
                return [
                    'product' => $product,
                    'access_type' => 'path',
                    'slug' => $slugNormalized,
                ];
            }
            $pathDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                ->where('value', $slugNormalized)
                ->with('product')
                ->first();
            if ($pathDomain && $pathDomain->product && $pathDomain->product->type === Product::TYPE_AREA_MEMBROS) {
                return [
                    'product' => $pathDomain->product,
                    'access_type' => 'path',
                    'slug' => $slugNormalized,
                ];
            }
        }

        $path = $request->path();
        if (str_starts_with($path, 'm/')) {
            $segments = explode('/', trim($path, '/'));
            $slug = $segments[1] ?? null;
            if ($slug !== null && $slug !== '') {
                $slugNormalized = strtolower($slug);
                $product = Product::where('checkout_slug', $slugNormalized)
                    ->where('type', Product::TYPE_AREA_MEMBROS)
                    ->first();
                if ($product) {
                    return [
                        'product' => $product,
                        'access_type' => 'path',
                        'slug' => $slugNormalized,
                    ];
                }
                $pathDomain = MemberAreaDomain::where('type', MemberAreaDomain::TYPE_PATH)
                    ->where('value', $slugNormalized)
                    ->with('product')
                    ->first();
                if ($pathDomain && $pathDomain->product && $pathDomain->product->type === Product::TYPE_AREA_MEMBROS) {
                    return [
                        'product' => $pathDomain->product,
                        'access_type' => 'path',
                        'slug' => $slugNormalized,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get the base URL for a product's member area (for links, PWA manifest, etc).
     */
    public function baseUrlForProduct(Product $product): string
    {
        $domain = $product->memberAreaDomain;
        $appUrl = rtrim(PublicAppUrl::base(), '/');
        $protocol = str_starts_with($appUrl, 'https') ? 'https' : 'http';

        if ($domain) {
            if ($domain->type === MemberAreaDomain::TYPE_CUSTOM && $domain->value) {
                return $protocol.'://'.$domain->value;
            }
            if ($domain->type === MemberAreaDomain::TYPE_SUBDOMAIN && config('members.subdomain_enabled')) {
                $base = config('members.subdomain_base');
                $slug = $domain->value ?: $product->checkout_slug;

                return $protocol.'://'.$slug.'.'.$base;
            }
            if ($domain->type === MemberAreaDomain::TYPE_PATH && $domain->value !== null && $domain->value !== '') {
                return $appUrl.'/m/'.$domain->value;
            }
        }

        return $appUrl.'/m/'.$product->checkout_slug;
    }

    /**
     * Link mágico assinado para o e-mail/WhatsApp de acesso (sempre com host público).
     */
    public function signedMagicAccessUrl(Product $product, User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        $base = $this->baseUrlForProduct($product);
        $expiresAt = $expiresAt ?? now()->addDays(7);

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

        $previousRoot = rtrim((string) config('app.url'), '/') ?: 'http://localhost';
        $previousScheme = parse_url($previousRoot, PHP_URL_SCHEME) ?: null;

        try {
            if ($useHostAccess) {
                PublicAppUrl::forceRoot($base);
            } else {
                // Path /m/{slug}: força origem pública (não o Host do request/queue).
                PublicAppUrl::forceRoot(PublicAppUrl::origin($base));
            }

            if ($useHostAccess) {
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
            URL::forceRootUrl($previousRoot);
            if (is_string($previousScheme) && $previousScheme !== '') {
                URL::forceScheme($previousScheme);
            }
        }
    }
}
