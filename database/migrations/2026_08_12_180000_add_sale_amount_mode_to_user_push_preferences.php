<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_push_preferences')) {
            return;
        }

        if (! Schema::hasColumn('user_push_preferences', 'sale_amount_mode')) {
            Schema::table('user_push_preferences', function (Blueprint $table) {
                $table->string('sale_amount_mode', 16)->default('gross')->after('show_sale_amount');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_push_preferences')) {
            return;
        }

        if (Schema::hasColumn('user_push_preferences', 'sale_amount_mode')) {
            Schema::table('user_push_preferences', function (Blueprint $table) {
                $table->dropColumn('sale_amount_mode');
            });
        }
    }
};
