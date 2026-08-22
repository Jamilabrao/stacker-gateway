<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'cnpj_lookup')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('cnpj_lookup')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'cnpj_lookup')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cnpj_lookup');
        });
    }
};
