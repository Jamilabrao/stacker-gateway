<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige instalações PostgreSQL/SQLite onde 2026_03_09_100003 não aplicou colunas da API
 * (aquela migration retornava cedo fora de MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('api_applications')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'api_application_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('api_application_id')->nullable()->constrained('api_applications')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('orders', 'api_checkout_session_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('api_checkout_session_id')->nullable()->index();
            });
        }

        $this->ensureOrdersProductIdNullable();
    }

    private function ensureOrdersProductIdNullable(): void
    {
        if (! Schema::hasColumn('orders', 'product_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                "SELECT is_nullable FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'orders' AND column_name = 'product_id'"
            );
            if ($row && strtoupper((string) $row->is_nullable) === 'YES') {
                return;
            }

            $fkRows = DB::select(
                "SELECT tc.constraint_name
                 FROM information_schema.table_constraints AS tc
                 JOIN information_schema.key_column_usage AS kcu
                   ON tc.constraint_schema = kcu.constraint_schema
                  AND tc.constraint_name = kcu.constraint_name
                 WHERE tc.table_schema = current_schema()
                   AND tc.table_name = 'orders'
                   AND tc.constraint_type = 'FOREIGN KEY'
                   AND kcu.column_name = 'product_id'"
            );
            foreach ($fkRows as $fk) {
                $name = $fk->constraint_name ?? null;
                if ($name) {
                    DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS "'.str_replace('"', '""', $name).'"');
                }
            }

            DB::statement('ALTER TABLE orders ALTER COLUMN product_id DROP NOT NULL');

            $hasFk = DB::selectOne(
                "SELECT 1 FROM information_schema.table_constraints AS tc
                 JOIN information_schema.key_column_usage AS kcu
                   ON tc.constraint_schema = kcu.constraint_schema
                  AND tc.constraint_name = kcu.constraint_name
                 WHERE tc.table_schema = current_schema()
                   AND tc.table_name = 'orders'
                   AND tc.constraint_type = 'FOREIGN KEY'
                   AND kcu.column_name = 'product_id'
                 LIMIT 1"
            );
            if (! $hasFk) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                });
            }

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $schema = DB::getDatabaseName();
            $row = DB::selectOne(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'product_id' LIMIT 1",
                [$schema]
            );
            if ($row && strtoupper((string) $row->IS_NULLABLE) === 'YES') {
                return;
            }

            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropForeign(['product_id']);
                });
            } catch (\Throwable) {
                // FK pode já ter sido removida
            }

            Schema::table('orders', function (Blueprint $table) {
                $table->string('product_id', 36)->nullable()->change();
            });

            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                });
            } catch (\Throwable) {
                // FK já existe
            }

            return;
        }

        if ($driver === 'sqlite') {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropForeign(['product_id']);
                });
            } catch (\Throwable) {
            }
            // SQLite em testes: product_id já costuma ser nullable após migrações de UUID.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'api_application_id')) {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropForeign(['api_application_id']);
                });
            } catch (\Throwable) {
            }
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('api_application_id');
            });
        }

        if (Schema::hasColumn('orders', 'api_checkout_session_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('api_checkout_session_id');
            });
        }
    }
};
