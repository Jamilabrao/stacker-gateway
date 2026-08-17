<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('action_group', 32)->index();
            $table->string('source', 16)->default('panel')->index();
            $table->string('target_type', 80)->nullable()->index();
            $table->string('target_id', 64)->nullable()->index();
            $table->string('summary', 255);
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['action_group', 'created_at']);
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_activity_logs');
    }
};
