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
            if (! Schema::hasColumn('checkout_sessions', 'utm_content')) {
                $table->text('utm_content')->nullable()->after('utm_campaign');
            }
            if (! Schema::hasColumn('checkout_sessions', 'utm_term')) {
                $table->text('utm_term')->nullable()->after('utm_content');
            }
            if (! Schema::hasColumn('checkout_sessions', 'sck')) {
                $table->text('sck')->nullable()->after('utm_term');
            }
            if (! Schema::hasColumn('checkout_sessions', 'src')) {
                $table->text('src')->nullable()->after('sck');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }
        Schema::table('checkout_sessions', function (Blueprint $table) {
            foreach (['utm_content', 'utm_term', 'sck', 'src'] as $col) {
                if (Schema::hasColumn('checkout_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
