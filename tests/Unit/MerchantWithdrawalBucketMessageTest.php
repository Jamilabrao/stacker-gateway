<?php

namespace Tests\Unit;

use App\Http\Controllers\SellerFinancialController;
use App\Models\TenantWallet;
use App\Services\MerchantWithdrawalService;
use ReflectionMethod;
use Tests\TestCase;

class MerchantWithdrawalBucketMessageTest extends TestCase
{
    public function test_insufficient_bucket_message_lists_all_wallets(): void
    {
        $wallet = new TenantWallet([
            'available_pix' => 100.0,
            'available_card' => 1334.46,
            'available_boleto' => 0.0,
        ]);

        $method = new ReflectionMethod(MerchantWithdrawalService::class, 'insufficientBucketBalanceMessage');
        $method->setAccessible(true);

        $msg = $method->invoke(null, $wallet, 'pix', 100.0);

        $this->assertStringContainsString('carteira PIX', $msg);
        $this->assertStringContainsString('disponível: R$ 100,00', $msg);
        $this->assertStringContainsString('PIX: R$ 100,00', $msg);
        $this->assertStringContainsString('Cartão: R$ 1.334,46', $msg);
        $this->assertStringContainsString('Boleto: R$ 0,00', $msg);
        $this->assertStringContainsString('Escolha a carteira correta', $msg);
    }

    public function test_parse_brl_amount_accepts_thousand_separator(): void
    {
        $method = new ReflectionMethod(SellerFinancialController::class, 'parseBrlAmountToFloat');
        $method->setAccessible(true);

        $this->assertSame(1334.46, $method->invoke(null, '1.334,46'));
        $this->assertSame(1334.46, $method->invoke(null, '1334.46'));
        $this->assertSame(1000.0, $method->invoke(null, '1.000,00'));
    }
}
