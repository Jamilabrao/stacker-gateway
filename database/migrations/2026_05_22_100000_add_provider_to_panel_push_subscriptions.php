<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->string('provider', 16)->default('vapid')->after('tenant_id');
            $table->string('fcm_token', 512)->nullable()->after('endpoint');
            $table->string('device_label', 120)->nullable()->after('user_agent');
            $table->timestamp('last_used_at')->nullable()->after('device_label');
        });

        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->index('provider');
            $table->index('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropIndex(['fcm_token']);
            $table->dropColumn(['provider', 'fcm_token', 'device_label', 'last_used_at']);
        });
    }
};
