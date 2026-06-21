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
            if (! Schema::hasColumn('checkout_sessions', 'meta_fbclid')) {
                $table->string('meta_fbclid', 512)->nullable()->after('meta_fbc');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'meta_fbclid')) {
                $table->dropColumn('meta_fbclid');
            }
        });
    }
};
