<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event_name', 64);
            $table->string('event_id', 128);
            $table->string('context_type', 32);
            $table->unsignedBigInteger('context_id');
            $table->string('pixel_id', 64);
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'pixel_id'], 'meta_tracking_events_event_pixel_unique');
            $table->index(['context_type', 'context_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_tracking_events');
    }
};
