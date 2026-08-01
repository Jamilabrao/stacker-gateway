<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_achievements')) {
            Schema::table('sales_achievements', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_achievements', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }
                if (! Schema::hasColumn('sales_achievements', 'metric_type')) {
                    $table->string('metric_type', 32)->default('revenue')->after('description');
                }
                if (! Schema::hasColumn('sales_achievements', 'reward_name')) {
                    $table->string('reward_name', 180)->nullable()->after('is_active');
                }
                if (! Schema::hasColumn('sales_achievements', 'reward_description')) {
                    $table->text('reward_description')->nullable()->after('reward_name');
                }
                if (! Schema::hasColumn('sales_achievements', 'reward_image')) {
                    $table->string('reward_image', 2048)->nullable()->after('reward_description');
                }
                if (! Schema::hasColumn('sales_achievements', 'reward_internal_notes')) {
                    $table->text('reward_internal_notes')->nullable()->after('reward_image');
                }
            });
        }

        if (! Schema::hasTable('sales_achievement_unlocks')) {
            Schema::create('sales_achievement_unlocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('sales_achievement_id')->constrained('sales_achievements')->cascadeOnDelete();
                $table->timestamp('unlocked_at');
                $table->decimal('metric_value_at_unlock', 15, 2)->default(0);
                $table->string('metric_type', 32)->default('revenue');
                $table->decimal('threshold_snapshot', 15, 2)->default(0);
                $table->string('name_snapshot', 180);
                $table->string('image_snapshot', 2048)->nullable();
                $table->string('reward_name_snapshot', 180)->nullable();
                $table->text('reward_description_snapshot')->nullable();
                $table->string('reward_image_snapshot', 2048)->nullable();
                $table->string('reward_status', 32)->default('pending')->index();
                $table->timestamp('reward_sent_at')->nullable();
                $table->string('reward_carrier', 120)->nullable();
                $table->string('reward_tracking_code', 120)->nullable();
                $table->text('reward_admin_notes')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'sales_achievement_id'], 'sales_ach_unlock_tenant_achievement_unique');
            });
        }

        if (! Schema::hasTable('sales_achievement_reward_status_history')) {
            Schema::create('sales_achievement_reward_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_achievement_unlock_id')
                    ->constrained('sales_achievement_unlocks')
                    ->cascadeOnDelete();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32);
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('note')->nullable();
                $table->string('tracking_code', 120)->nullable();
                $table->string('carrier', 120)->nullable();
                $table->timestamps();
                $table->index(['sales_achievement_unlock_id', 'created_at'], 'sales_ach_reward_hist_unlock_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_achievement_reward_status_history');
        Schema::dropIfExists('sales_achievement_unlocks');

        if (Schema::hasTable('sales_achievements')) {
            Schema::table('sales_achievements', function (Blueprint $table) {
                foreach ([
                    'description',
                    'metric_type',
                    'reward_name',
                    'reward_description',
                    'reward_image',
                    'reward_internal_notes',
                ] as $col) {
                    if (Schema::hasColumn('sales_achievements', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
