<?php

namespace Tests\Unit;

use App\Services\CajuPay\CajuPaySdkCheckoutService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CajuPaySdkSessionStatusTest extends TestCase
{
    public function test_public_session_status_prefers_payment_status_when_session_active(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response([
                'status' => 'active',
                'payment_status' => 'paid',
            ], 200),
        ]);

        $service = new CajuPaySdkCheckoutService;
        $status = $service->getPublicSessionStatus('tok_public_abcdef1234567890');

        $this->assertSame('paid', $status);
    }

    public function test_public_session_status_maps_active_without_payment_to_pending(): void
    {
        Http::fake([
            'https://api.cajupay.com.br/api/sdk/public/checkout/sessions/*' => Http::response([
                'status' => 'active',
            ], 200),
        ]);

        $service = new CajuPaySdkCheckoutService;
        $status = $service->getPublicSessionStatus('tok_public_abcdef1234567890');

        $this->assertSame('pending', $status);
    }
}
