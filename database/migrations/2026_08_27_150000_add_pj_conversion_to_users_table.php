<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'pj_conversion')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('pj_conversion')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'pj_conversion')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pj_conversion');
        });
    }
};
