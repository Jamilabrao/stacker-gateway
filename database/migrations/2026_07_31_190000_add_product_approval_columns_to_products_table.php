<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'approval_status')) {
                $table->string('approval_status', 32)->default('approved')->after('admin_blocked');
            }
            if (! Schema::hasColumn('products', 'approval_reason')) {
                $table->text('approval_reason')->nullable()->after('approval_status');
            }
            if (! Schema::hasColumn('products', 'approval_source')) {
                $table->string('approval_source', 32)->nullable()->after('approval_reason');
            }
            if (! Schema::hasColumn('products', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('approval_source');
            }
            if (! Schema::hasColumn('products', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
        });

        // Produtos existentes permanecem vendáveis (não entram em análise).
        if (Schema::hasColumn('products', 'approval_status')) {
            DB::table('products')
                ->whereNull('approval_status')
                ->orWhere('approval_status', '')
                ->update([
                    'approval_status' => 'approved',
                    'approval_source' => 'migration',
                    'reviewed_at' => now(),
                ]);

            DB::table('products')
                ->where('approval_status', 'approved')
                ->whereNull('approval_source')
                ->update([
                    'approval_source' => 'migration',
                    'reviewed_at' => DB::raw('COALESCE(reviewed_at, created_at, CURRENT_TIMESTAMP)'),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['reviewed_at', 'reviewed_by', 'approval_source', 'approval_reason', 'approval_status'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
