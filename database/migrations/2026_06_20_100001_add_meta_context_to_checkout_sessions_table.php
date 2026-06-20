<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'meta_fbp')) {
                $table->string('meta_fbp', 512)->nullable()->after('customer_ip');
            }
            if (! Schema::hasColumn('checkout_sessions', 'meta_fbc')) {
                $table->string('meta_fbc', 512)->nullable()->after('meta_fbp');
            }
            if (! Schema::hasColumn('checkout_sessions', 'meta_user_agent')) {
                $table->text('meta_user_agent')->nullable()->after('meta_fbc');
            }
            if (! Schema::hasColumn('checkout_sessions', 'meta_page_url')) {
                $table->text('meta_page_url')->nullable()->after('meta_user_agent');
            }
            if (! Schema::hasColumn('checkout_sessions', 'affiliate_ref')) {
                $table->string('affiliate_ref', 64)->nullable()->after('meta_page_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            foreach (['meta_fbp', 'meta_fbc', 'meta_user_agent', 'meta_page_url', 'affiliate_ref'] as $col) {
                if (Schema::hasColumn('checkout_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
