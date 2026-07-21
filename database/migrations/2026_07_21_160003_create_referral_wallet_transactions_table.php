<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referral_wallet_transactions')) {
            return;
        }

        Schema::create('referral_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referral_commission_id')->nullable()->constrained('referral_commissions')->nullOnDelete();
            $table->foreignId('referral_withdrawal_id')->nullable()->index();
            $table->string('type', 40);
            $table->string('reference', 120)->unique();
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_wallet_transactions');
    }
};
