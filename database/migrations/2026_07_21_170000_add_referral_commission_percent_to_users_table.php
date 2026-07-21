<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'referral_commission_percent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('referral_commission_percent', 8, 4)->nullable()->after('referred_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'referral_commission_percent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('referral_commission_percent');
            });
        }
    }
};
