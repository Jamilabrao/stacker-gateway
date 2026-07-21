<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referral_commissions')) {
            return;
        }

        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('platform_fee', 14, 2);
            $table->decimal('commission_percent', 8, 4);
            $table->decimal('amount', 14, 2);
            $table->string('status', 32)->default('available');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'referrer_user_id']);
            $table->index(['referrer_user_id', 'status']);
            $table->index(['referred_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
    }
};
