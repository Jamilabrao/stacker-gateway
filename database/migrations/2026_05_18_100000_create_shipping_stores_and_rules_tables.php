<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_stores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('origin_zip', 9)->nullable();
            $table->string('origin_street')->nullable();
            $table->string('origin_number', 32)->nullable();
            $table->string('origin_complement')->nullable();
            $table->string('origin_neighborhood')->nullable();
            $table->string('origin_city')->nullable();
            $table->string('origin_state', 2)->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_store_id')->constrained('shipping_stores')->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('match_type', 32);
            $table->json('match_config')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->unsignedSmallInteger('delivery_days_min')->nullable();
            $table->unsignedSmallInteger('delivery_days_max')->nullable();
            $table->timestamps();

            $table->index(['shipping_store_id', 'priority']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('shipping_store_id')->nullable()->after('refund_policy_days')->constrained('shipping_stores')->nullOnDelete();
            $table->json('physical_config')->nullable()->after('shipping_store_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shipping_amount', 10, 2)->default(0)->after('amount');
            $table->foreignId('shipping_store_id')->nullable()->after('shipping_amount')->constrained('shipping_stores')->nullOnDelete();
            $table->foreignId('shipping_rule_id')->nullable()->after('shipping_store_id')->constrained('shipping_rules')->nullOnDelete();
            $table->json('shipping_address')->nullable()->after('shipping_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shipping_rule_id']);
            $table->dropForeign(['shipping_store_id']);
            $table->dropColumn(['shipping_amount', 'shipping_store_id', 'shipping_rule_id', 'shipping_address']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['shipping_store_id']);
            $table->dropColumn(['shipping_store_id', 'physical_config']);
        });

        Schema::dropIfExists('shipping_rules');
        Schema::dropIfExists('shipping_stores');
    }
};
