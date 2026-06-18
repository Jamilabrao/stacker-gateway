<?php

namespace Tests\Feature;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CajuPayAccountsMigrationTest extends TestCase
{
    public function test_cajupay_accounts_table_and_legacy_migration_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('cajupay_accounts'));
        $this->assertTrue(Schema::hasColumn('users', 'cajupay_account_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'cajupay_account_id'));
    }

    public function test_legacy_global_credential_can_be_represented_as_default_account(): void
    {
        if (CajuPayAccount::query()->exists()) {
            $this->assertTrue(CajuPayAccount::query()->where('is_default', true)->exists());

            return;
        }

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'cajupay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_legacy',
            'secret_key' => 'gsk_legacy',
        ]);
        $cred->save();

        $account = CajuPayAccount::create([
            'name' => 'Conta padrão',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $account->setEncryptedCredentials($cred->getDecryptedCredentials());
        $account->save();

        $default = CajuPayAccount::query()->default()->first();
        $this->assertNotNull($default);
        $this->assertSame('gpk_legacy', $default->getDecryptedCredentials()['public_key']);
    }
}
