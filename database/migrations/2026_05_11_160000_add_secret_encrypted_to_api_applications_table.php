<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_applications')) {
            return;
        }

        Schema::table('api_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('api_applications', 'secret_encrypted')) {
                $table->text('secret_encrypted')->nullable()->after('secret_key_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('api_applications')) {
            return;
        }

        Schema::table('api_applications', function (Blueprint $table) {
            if (Schema::hasColumn('api_applications', 'secret_encrypted')) {
                $table->dropColumn('secret_encrypted');
            }
        });
    }
};
