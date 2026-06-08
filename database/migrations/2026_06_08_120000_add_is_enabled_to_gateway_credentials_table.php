<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gateway_credentials')) {
            return;
        }

        Schema::table('gateway_credentials', function (Blueprint $table) {
            if (! Schema::hasColumn('gateway_credentials', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('is_connected');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('gateway_credentials')) {
            return;
        }

        Schema::table('gateway_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('gateway_credentials', 'is_enabled')) {
                $table->dropColumn('is_enabled');
            }
        });
    }
};
