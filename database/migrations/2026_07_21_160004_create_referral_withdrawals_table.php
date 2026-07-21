<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referral_withdrawals')) {
            Schema::create('referral_withdrawals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('status', 32)->default('pending');
                $table->string('failed_reason')->nullable();
                $table->text('notes')->nullable();
                $table->string('currency', 3)->default('BRL');
                $table->json('pix_snapshot')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'status']);
            });
        }

        if (Schema::hasTable('referral_wallet_transactions')
            && Schema::hasColumn('referral_wallet_transactions', 'referral_withdrawal_id')) {
            Schema::table('referral_wallet_transactions', function (Blueprint $table) {
                $table->foreign('referral_withdrawal_id')
                    ->references('id')
                    ->on('referral_withdrawals')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('referral_wallet_transactions')
            && Schema::hasColumn('referral_wallet_transactions', 'referral_withdrawal_id')) {
            Schema::table('referral_wallet_transactions', function (Blueprint $table) {
                $table->dropForeign(['referral_withdrawal_id']);
            });
        }

        Schema::dropIfExists('referral_withdrawals');
    }
};
