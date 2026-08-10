<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'support_email')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('support_email', 255)->nullable()->after('notification_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'support_email')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('support_email');
        });
    }
};
