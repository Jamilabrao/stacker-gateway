<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('med_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('cajupay_dispute_id', 64)->unique();
            $table->string('cajupay_payment_id', 64)->nullable()->index();
            $table->string('status', 32)->default('open')->index();
            $table->string('outcome', 32)->nullable();
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency', 8)->default('BRL');
            $table->string('txid', 128)->nullable();
            $table->text('defense_text')->nullable();
            $table->timestamp('defended_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('med_disputes');
    }
};
