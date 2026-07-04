<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_integrax_settings', function (Blueprint $table) {
            $table->boolean('sms_checkout_only')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('platform_integrax_settings', function (Blueprint $table) {
            $table->dropColumn('sms_checkout_only');
        });
    }
};
