<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_gateway_webhooks')) {
            return;
        }

        Schema::table('inbound_gateway_webhooks', function (Blueprint $table) {
            if (! Schema::hasColumn('inbound_gateway_webhooks', 'response_body')) {
                $table->string('response_body', 512)->nullable()->after('http_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbound_gateway_webhooks')) {
            return;
        }

        Schema::table('inbound_gateway_webhooks', function (Blueprint $table) {
            if (Schema::hasColumn('inbound_gateway_webhooks', 'response_body')) {
                $table->dropColumn('response_body');
            }
        });
    }
};
