<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel_push_campaigns')) {
            Schema::create('panel_push_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('title', 120);
                $table->string('body', 500);
                $table->string('image_url', 2048)->nullable();
                $table->string('target_url', 2048)->nullable();
                $table->string('audience', 64)->default('all_subscribers');
                $table->json('audience_filters')->nullable();
                $table->string('send_mode', 16)->default('now'); // now|scheduled
                $table->timestamp('scheduled_at')->nullable()->index();
                $table->string('timezone', 64)->default('America/Sao_Paulo');
                $table->boolean('silent')->default(false);
                $table->string('status', 32)->default('draft')->index();
                $table->string('idempotency_key', 64)->unique();
                $table->unsignedInteger('eligible_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('invalid_count')->default(0);
                $table->unsignedInteger('expired_count')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('processing_started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('result_meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('panel_push_daily_summary_logs')) {
            Schema::create('panel_push_daily_summary_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->date('reference_date');
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('orders_total', 12, 2)->default(0);
                $table->json('by_method')->nullable();
                $table->string('status', 32)->default('sent');
                $table->timestamps();
                $table->unique(['tenant_id', 'reference_date'], 'push_daily_summary_tenant_date_unique');
            });
        }

        if (! Schema::hasTable('user_push_preferences')) {
            Schema::create('user_push_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('sale_approved')->default(true);
                $table->boolean('pix_generated')->default(true);
                $table->boolean('boleto_generated')->default(true);
                $table->boolean('withdrawal_paid')->default(true);
                $table->boolean('affiliate_sale_approved')->default(true);
                $table->boolean('affiliate_enrollment_approved')->default(true);
                $table->boolean('daily_summary')->default(true);
                $table->boolean('system')->default(true);
                $table->boolean('show_product_name')->default(true);
                $table->boolean('show_sale_amount')->default(true);
                $table->boolean('show_payment_method')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'notification_name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('notification_name', 80)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'notification_name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('notification_name');
            });
        }
        Schema::dropIfExists('user_push_preferences');
        Schema::dropIfExists('panel_push_daily_summary_logs');
        Schema::dropIfExists('panel_push_campaigns');
    }
};
