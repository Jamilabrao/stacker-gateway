<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\PlatformTotpService;
use Tests\TestCase;

class PlatformTotpTest extends TestCase
{
    public function test_platform_admin_can_enable_and_verify_totp(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $setup = PlatformTotpService::beginEnrollment($admin->fresh());
        $this->assertNotEmpty($setup['secret']);

        $code = $this->totpCodeForSecret($setup['secret']);
        $this->assertTrue(PlatformTotpService::confirmEnrollment($admin->fresh(), $code));
        $this->assertTrue(PlatformTotpService::isEnabledFor($admin->fresh()));
        $this->assertTrue(PlatformTotpService::verifyCodeForUser($admin->fresh(), $code));
    }

    public function test_manual_withdrawal_approval_requires_pin_when_auto_disabled(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        \App\Models\Setting::set('platform_auto_withdrawal_enabled', false, null);
        \App\Services\Withdrawal\WithdrawalPolicyService::setManualApprovalPin('9999');

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $withdrawal = \App\Models\Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 10,
            'fee_amount' => 0,
            'net_amount' => 10,
            'bucket' => 'pix',
            'status' => 'pending',
            'currency' => 'BRL',
        ]);

        $withoutPin = $this->actingAs($admin)->post(route('plataforma.financeiro.saques.approve', $withdrawal), [
            'payout_manual' => true,
            'manual_confirm_external' => true,
        ]);
        $withoutPin->assertRedirect(route('plataforma.saques.index'));
        $withoutPin->assertSessionHas('error');

        $withdrawal->refresh();
        $this->assertSame('pending', $withdrawal->status);
    }

    private function totpCodeForSecret(string $secret): string
    {
        $key = $this->base32Decode($secret);
        $timeSlice = (int) floor(time() / 30);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        foreach (str_split($secret) as $char) {
            $val = strpos($alphabet, $char);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
