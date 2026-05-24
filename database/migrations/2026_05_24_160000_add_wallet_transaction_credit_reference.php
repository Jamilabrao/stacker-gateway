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

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_transactions', 'credit_reference')) {
                $table->string('credit_reference', 120)->nullable()->after('type');
                $table->unique('credit_reference');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return;
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('wallet_transactions', 'credit_reference')) {
                $table->dropUnique(['credit_reference']);
                $table->dropColumn('credit_reference');
            }
        });
    }
};
