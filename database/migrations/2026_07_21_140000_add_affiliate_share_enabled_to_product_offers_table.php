<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_offers')) {
            return;
        }
        if (Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            return;
        }
        Schema::table('product_offers', function (Blueprint $table) {
            $table->boolean('affiliate_share_enabled')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_offers') || ! Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            return;
        }
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropColumn('affiliate_share_enabled');
        });
    }
};
