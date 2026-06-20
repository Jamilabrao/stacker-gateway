<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utmify_order_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('utmify_integration_id')->index();
            $table->string('utmify_status', 32);
            $table->string('dispatch_status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_id', 'utmify_integration_id', 'utmify_status'],
                'utmify_order_dispatches_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utmify_order_dispatches');
    }
};
