<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class MemberAreaMagicAccessToken
{
    private const CACHE_PREFIX = 'member_area_magic_v1:';

    private const TTL_SECONDS = 86400;

    private const TOKEN_LENGTH = 64;

    /**
     * Create a one-time stored token for magic login (e-mail link).
     */
    public function mint(Product $product, User $user): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
        $key = self::CACHE_PREFIX.hash('sha256', $token);
        Cache::put($key, [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ], now()->addSeconds(self::TTL_SECONDS));

        return $token;
    }

    /**
     * Resolve user id if the token exists, matches the product, and is not expired.
     */
    public function resolveUserId(string $token, Product $product): ?int
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_xdigit($token)) {
            return null;
        }
        $key = self::CACHE_PREFIX.hash('sha256', $token);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            return null;
        }
        if ((int) ($payload['product_id'] ?? 0) !== (int) $product->id) {
            return null;
        }
        $uid = (int) ($payload['user_id'] ?? 0);

        return $uid > 0 ? $uid : null;
    }

    /**
     * Invalida o token após login bem-sucedido (one-time).
     */
    public function consume(string $token, Product $product): void
    {
        if (strlen($token) !== self::TOKEN_LENGTH || ! ctype_xdigit($token)) {
            return;
        }
        $key = self::CACHE_PREFIX.hash('sha256', $token);
        $payload = Cache::pull($key);
        if (! is_array($payload)) {
            return;
        }
        if ((int) ($payload['product_id'] ?? 0) !== (int) $product->id) {
            Cache::put($key, $payload, now()->addSeconds(self::TTL_SECONDS));
        }
    }
}
