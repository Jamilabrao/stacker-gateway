<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_managers')) {
            Schema::create('account_managers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('phone', 32)->nullable();
                $table->string('avatar')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('show_email')->default(true);
                $table->boolean('show_phone')->default(true);
                $table->boolean('show_whatsapp')->default(true);
                $table->boolean('show_photo')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'id']);
                $table->unique('email');
            });
        }

        if (! Schema::hasTable('account_manager_assignments')) {
            Schema::create('account_manager_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('account_manager_id')->nullable()->constrained('account_managers')->nullOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at');
                $table->timestamp('ended_at')->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('source', 64)->default('manual');
                $table->timestamps();

                $table->index(['merchant_user_id', 'ended_at']);
                $table->index(['account_manager_id', 'ended_at']);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'account_manager_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('account_manager_id')
                    ->nullable()
                    ->constrained('account_managers')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'account_manager_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('account_manager_id');
            });
        }

        Schema::dropIfExists('account_manager_assignments');
        Schema::dropIfExists('account_managers');
    }
};
