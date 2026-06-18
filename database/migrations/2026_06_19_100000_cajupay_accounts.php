<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cajupay_accounts')) {
            Schema::create('cajupay_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_default')->default(false)->index();
                $table->text('credentials')->nullable();
                $table->boolean('is_connected')->default(false);
                $table->boolean('is_enabled')->default(true);
                $table->json('webhook_setup_status')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'cajupay_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('cajupay_account_id')->nullable()->index()->after('merchant_gateway_order');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'cajupay_account_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('cajupay_account_id')->nullable()->index()->after('gateway_id');
            });
        }

        $this->migrateLegacyGlobalCredential();
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'cajupay_account_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('cajupay_account_id');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'cajupay_account_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('cajupay_account_id');
            });
        }

        Schema::dropIfExists('cajupay_accounts');
    }

    private function migrateLegacyGlobalCredential(): void
    {
        if (! Schema::hasTable('gateway_credentials') || ! Schema::hasTable('cajupay_accounts')) {
            return;
        }

        if (DB::table('cajupay_accounts')->exists()) {
            return;
        }

        $legacy = DB::table('gateway_credentials')
            ->whereNull('tenant_id')
            ->where('gateway_slug', 'cajupay')
            ->first();

        if ($legacy === null) {
            DB::table('cajupay_accounts')->insert([
                'name' => 'Conta padrão',
                'is_default' => true,
                'credentials' => null,
                'is_connected' => false,
                'is_enabled' => true,
                'webhook_setup_status' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('cajupay_accounts')->insert([
            'name' => 'Conta padrão',
            'is_default' => true,
            'credentials' => $legacy->credentials,
            'is_connected' => (bool) $legacy->is_connected,
            'is_enabled' => ($legacy->is_enabled ?? true) !== false,
            'webhook_setup_status' => null,
            'created_at' => $legacy->created_at ?? now(),
            'updated_at' => $legacy->updated_at ?? now(),
        ]);
    }
};
