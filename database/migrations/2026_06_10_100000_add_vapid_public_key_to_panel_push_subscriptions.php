<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->string('vapid_public_key', 512)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('vapid_public_key');
        });
    }
};
