<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrax_sms_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('checkout_session_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('event_type', 32);
            $table->string('phone', 20);
            $table->string('message', 160);
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['checkout_session_id', 'event_type']);
            $table->index(['order_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrax_sms_dispatches');
    }
};
