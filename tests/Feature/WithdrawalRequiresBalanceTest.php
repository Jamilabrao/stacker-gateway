<?php

namespace Tests\Feature;

use App\Models\TenantWallet;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WithdrawalRequiresBalanceTest extends TestCase
{
    public function test_withdrawal_without_balance_fails_and_does_not_create_record(): void
    {
        if (! Schema::hasTable('withdrawals') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet/withdrawal tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 0,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        $this->actingAs($seller);

        $res = $this->post('/financeiro/saque', [
            'amount' => '10.00',
            'bucket' => 'pix',
            'notes' => 'teste',
        ]);

        $res->assertSessionHasErrors(['amount']);

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_withdrawal_accepts_pt_br_amount_format_and_still_validates_balance(): void
    {
        if (! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        TenantWallet::query()->firstOrCreate(
            ['tenant_id' => $seller->id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'currency' => 'BRL',
                'available_pix' => 1000,
                'available_card' => 0,
                'available_boleto' => 0,
                'pending_pix' => 0,
                'pending_card' => 0,
                'pending_boleto' => 0,
            ]
        );

        $this->actingAs($seller);

        $res = $this->post('/financeiro/saque', [
            'amount' => '1.111,00',
            'bucket' => 'pix',
        ]);

        $res->assertSessionHasErrors(['amount']);
        $this->assertStringContainsString('Saldo', (string) session('errors')?->first('amount'));
    }
}

