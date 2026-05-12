<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garante colunas public_key / secret_key_hash em instalações onde a migration
 * anterior não foi executada (ex.: PostgreSQL sem migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_applications')) {
            return;
        }

        Schema::table('api_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('api_applications', 'public_key')) {
                $table->string('public_key', 80)->nullable()->unique()->after('api_key_hash');
            }
            if (! Schema::hasColumn('api_applications', 'secret_key_hash')) {
                $table->string('secret_key_hash', 255)->nullable()->after('public_key');
            }
        });
    }

    public function down(): void
    {
        // Colunas podem ter sido criadas por outra migration; rollback manual se necessário.
    }
};
