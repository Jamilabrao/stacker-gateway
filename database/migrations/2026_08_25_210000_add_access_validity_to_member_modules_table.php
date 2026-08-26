<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('member_modules', 'expire_after_days')) {
                $table->unsignedInteger('expire_after_days')->nullable()->after('release_at_date');
            }
            if (! Schema::hasColumn('member_modules', 'expire_at_date')) {
                $table->date('expire_at_date')->nullable()->after('expire_after_days');
            }
            if (! Schema::hasColumn('member_modules', 'renewal_price')) {
                $table->decimal('renewal_price', 10, 2)->nullable()->after('expire_at_date');
            }
        });

        if (! Schema::hasTable('member_module_accesses')) {
            Schema::create('member_module_accesses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('member_module_id')->constrained('member_modules')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->timestamp('access_starts_at');
                $table->timestamps();
                $table->index(['user_id', 'member_module_id']);
                $table->unique('order_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_module_accesses');

        Schema::table('member_modules', function (Blueprint $table) {
            foreach (['renewal_price', 'expire_at_date', 'expire_after_days'] as $column) {
                if (Schema::hasColumn('member_modules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
