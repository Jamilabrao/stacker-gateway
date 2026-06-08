<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_admin_notes')) {
            return;
        }

        Schema::create('merchant_admin_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_admin_notes');
    }
};
