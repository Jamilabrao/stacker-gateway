<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        try {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->unique(
                    ['withdrawal_id', 'type'],
                    'wallet_tx_withdrawal_complete_unique'
                );
            });
        } catch (\Throwable) {
            // Índice já existe ou há duplicatas legadas — markPaid continua idempotente em código.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        try {
            Schema::table('wallet_transactions', function (Blueprint $table) {
                $table->dropUnique('wallet_tx_withdrawal_complete_unique');
            });
        } catch (\Throwable) {
            //
        }
    }
};
