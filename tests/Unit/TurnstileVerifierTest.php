<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Checkout\TurnstileVerifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerifierTest extends TestCase
{
    public function test_verify_returns_true_when_cloudflare_accepts(): void
    {
        Setting::set('checkout_turnstile_secret_key', encrypt('test-secret'), null);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true], 200),
        ]);

        $ok = app(TurnstileVerifier::class)->verify('mock-token', '127.0.0.1');

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    }

    public function test_verify_returns_false_when_cloudflare_rejects(): void
    {
        Setting::set('checkout_turnstile_secret_key', encrypt('test-secret'), null);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false], 200),
        ]);

        $this->assertFalse(app(TurnstileVerifier::class)->verify('bad-token', '127.0.0.1'));
    }
}
