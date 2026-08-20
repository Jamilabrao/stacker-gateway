<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_gateway_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_slug', 64);
            $table->string('http_method', 16);
            $table->string('path', 255);
            $table->string('event', 191)->nullable();
            $table->string('transaction_id', 191)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['gateway_slug', 'created_at']);
            $table->index(['created_at']);
            $table->index(['transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_gateway_webhooks');
    }
};
