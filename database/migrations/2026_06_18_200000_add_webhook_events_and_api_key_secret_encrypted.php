<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_applications') && ! Schema::hasColumn('api_applications', 'webhook_events')) {
            Schema::table('api_applications', function (Blueprint $table) {
                $table->json('webhook_events')->nullable()->after('webhook_secret');
            });
        }

        if (Schema::hasTable('api_applications') && ! Schema::hasColumn('api_applications', 'webhook_enabled')) {
            Schema::table('api_applications', function (Blueprint $table) {
                $table->boolean('webhook_enabled')->default(true)->after('webhook_events');
            });
        }

        if (Schema::hasTable('api_keys') && ! Schema::hasColumn('api_keys', 'secret_encrypted')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->text('secret_encrypted')->nullable()->after('secret_key_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('api_applications') && Schema::hasColumn('api_applications', 'webhook_enabled')) {
            Schema::table('api_applications', function (Blueprint $table) {
                $table->dropColumn('webhook_enabled');
            });
        }

        if (Schema::hasTable('api_applications') && Schema::hasColumn('api_applications', 'webhook_events')) {
            Schema::table('api_applications', function (Blueprint $table) {
                $table->dropColumn('webhook_events');
            });
        }

        if (Schema::hasTable('api_keys') && Schema::hasColumn('api_keys', 'secret_encrypted')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropColumn('secret_encrypted');
            });
        }
    }
};
