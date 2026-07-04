<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrax_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->text('api_token')->nullable();
            $table->string('sender_from', 32)->nullable();
            $table->boolean('event_cart_recovery_enabled')->default(false);
            $table->boolean('event_order_paid_enabled')->default(false);
            $table->boolean('event_access_granted_enabled')->default(false);
            $table->boolean('event_pix_generated_enabled')->default(false);
            $table->string('message_cart_recovery', 160)->nullable();
            $table->string('message_order_paid', 160)->nullable();
            $table->string('message_access_granted', 160)->nullable();
            $table->string('message_pix_generated', 160)->nullable();
            $table->unsignedSmallInteger('cart_first_delay_minutes')->default(10);
            $table->unsignedInteger('cart_interval_minutes')->default(1440);
            $table->unsignedSmallInteger('cart_max_duration_hours')->default(72);
            $table->unsignedTinyInteger('cart_max_sends')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_integrax_settings');
    }
};
