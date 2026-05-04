<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\CajuPay\CajuPayPayoutService;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayPayoutOwnerDocumentTest extends TestCase
{
    public function test_send_withdrawal_uses_dict_payload_with_local_key_data(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-ext-1'], 200),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'aluno@example.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $w = Withdrawal::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50.00,
            'fee_amount' => 0,
            'net_amount' => 50.00,
            'bucket' => 'pix',
            'status' => 'pending',
        ]);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $w->fresh(),
            null,
            'aluno@example.com',
            'email',
            '52998224725'
        );
        $this->assertTrue($result['ok'] ?? false);

        Http::assertSent(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if (! str_ends_with($path, '/api/payouts')) {
                return false;
            }
            $json = json_decode($request->body(), true);

            return is_array($json)
                && (($json['destination']['method'] ?? null) === 'dict')
                && (($json['pix_key'] ?? null) === 'aluno@example.com')
                && (($json['pix_key_type'] ?? null) === 'email')
                && (($json['key_owner_document'] ?? null) === '52998224725')
                && ! array_key_exists('pix_key_id', $json);
        });

        $cred->delete();
    }

    public function test_send_withdrawal_fails_when_only_legacy_pix_key_id_exists(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key_id' => 'legacy-only',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $w = Withdrawal::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 50.00,
            'fee_amount' => 0,
            'net_amount' => 50.00,
            'bucket' => 'pix',
            'status' => 'pending',
        ]);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $w->fresh(),
            'legacy-only',
            '',
            '',
            '52998224725'
        );

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('Configure a chave PIX e o tipo da chave', (string) ($result['error'] ?? ''));

        $cred->delete();
    }

    public function test_send_withdrawal_uses_owner_document_from_pix_key_even_if_profile_document_differs(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response(['id' => 'payout-ext-2'], 200),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'document' => '11222333000181',
        ]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'financeiro@example.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $w = Withdrawal::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 60.00,
            'fee_amount' => 0,
            'net_amount' => 60.00,
            'bucket' => 'pix',
            'status' => 'pending',
        ]);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $w->fresh(),
            null,
            'financeiro@example.com',
            'email',
            '52998224725'
        );
        $this->assertTrue($result['ok'] ?? false);

        Http::assertSent(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            if (! str_ends_with($path, '/api/payouts')) {
                return false;
            }
            $json = json_decode($request->body(), true);

            return is_array($json)
                && (($json['pix_key'] ?? null) === 'financeiro@example.com')
                && (($json['pix_key_type'] ?? null) === 'email')
                && (($json['key_owner_document'] ?? null) === '52998224725');
        });

        $cred->delete();
    }

    public function test_insufficient_funds_returns_friendly_message_and_error_code(): void
    {
        if (! Schema::hasTable('withdrawals')) {
            $this->markTestSkipped('withdrawals table');
        }

        config(['services.cajupay.base_url' => 'https://api.cajupay.com.br']);

        Http::fake([
            'https://api.cajupay.com.br/*' => Http::response([
                'error' => 'insufficient_funds',
                'user_message' => 'Saldo insuficiente para este valor de saque (incluindo taxas, se houver).',
            ], 400),
        ]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'test-public',
            'secret_key' => 'test-secret',
            'cajupay_payout_min_brl' => '0',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_payout_brl' => '0',
        ]);
        $cred->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'payout_settings' => [
                'cajupay_pix_key' => 'a@b.com',
                'cajupay_pix_key_type' => 'email',
                'cajupay_pix_key_owner_document' => '52998224725',
            ],
        ])->save();

        $w = Withdrawal::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 10.00,
            'fee_amount' => 0,
            'net_amount' => 10.00,
            'bucket' => 'pix',
            'status' => 'pending',
        ]);

        $result = (new CajuPayPayoutService)->sendWithdrawalToPixKey(
            $w->fresh(),
            null,
            'a@b.com',
            'email',
            '52998224725'
        );

        $this->assertFalse($result['ok'] ?? true);
        $this->assertSame('insufficient_funds', $result['cajupay_error_code'] ?? null);
        $this->assertStringContainsString('Saldo insuficiente', (string) ($result['error'] ?? ''));

        $cred->delete();
    }

    public function test_brazilian_document_digits_normalizes(): void
    {
        $this->assertSame('52998224725', BrazilianDocumentDigits::onlyDigits('529.982.247-25'));
        $this->assertTrue(BrazilianDocumentDigits::isValidCpfOrCnpjLength('52998224725'));
        $this->assertTrue(BrazilianDocumentDigits::isValidCpfOrCnpjLength('11222333000181'));
    }
}

