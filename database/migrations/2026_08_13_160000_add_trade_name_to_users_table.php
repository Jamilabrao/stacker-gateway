<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'trade_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $after = Schema::hasColumn('users', 'company_name') ? 'company_name' : 'name';
            $table->string('trade_name', 255)->nullable()->after($after);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'trade_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trade_name');
        });
    }
};
