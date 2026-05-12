<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
        Schema::table('api_applications', function (Blueprint $table) {
            if (Schema::hasColumn('api_applications', 'public_key')) {
                $table->dropUnique('api_applications_public_key_unique');
                $table->dropColumn('public_key');
            }
            if (Schema::hasColumn('api_applications', 'secret_key_hash')) {
                $table->dropColumn('secret_key_hash');
            }
        });
    }
};
