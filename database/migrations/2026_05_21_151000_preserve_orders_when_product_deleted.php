<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos não podem ser apagados em cascata ao remover produto (corrida de faturamento / histórico).
 * Compatível com PostgreSQL, MySQL/MariaDB e SQLite (testes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('products')) {
            return;
        }

        $this->ensureProductForeignKeyNullOnDelete('orders', 'product_id', true);

        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'product_id')) {
            $this->ensureProductForeignKeyNullOnDelete('order_items', 'product_id', true);
        }
    }

    /**
     * Troca FK product_id → products para ON DELETE SET NULL (Laravel: nullOnDelete).
     */
    private function ensureProductForeignKeyNullOnDelete(string $table, string $column, bool $ensureNullable): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->ensureProductForeignKeyNullOnDeletePgsql($table, $column, $ensureNullable);

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->ensureProductForeignKeyNullOnDeleteMysql($table, $column, $ensureNullable);

            return;
        }

        $this->dropProductForeignKeyGeneric($table, $column);
        if (! $this->hasProductForeignKey($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)->references('id')->on('products')->nullOnDelete();
            });
        }
    }

    private function ensureProductForeignKeyNullOnDeletePgsql(string $table, string $column, bool $ensureNullable): void
    {
        if ($ensureNullable && ! $this->isColumnNullablePgsql($table, $column)) {
            $this->dropProductForeignKeyPgsql($table, $column);
            DB::statement('ALTER TABLE "'.$this->escapePgIdentifier($table).'" ALTER COLUMN "'.$this->escapePgIdentifier($column).'" DROP NOT NULL');
        } else {
            $this->dropProductForeignKeyPgsql($table, $column);
        }

        if ($this->hasProductForeignKeyWithNullOnDeletePgsql($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->foreign($column)->references('id')->on('products')->nullOnDelete();
        });
    }

    private function ensureProductForeignKeyNullOnDeleteMysql(string $table, string $column, bool $ensureNullable): void
    {
        $this->dropProductForeignKeyGeneric($table, $column);

        if ($ensureNullable) {
            $schema = DB::getDatabaseName();
            $row = DB::selectOne(
                "SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
                [$schema, $table, $column]
            );
            if ($row && strtoupper((string) $row->IS_NULLABLE) === 'NO') {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    if ($column === 'product_id') {
                        $blueprint->string('product_id', 36)->nullable()->change();
                    } else {
                        $blueprint->string($column)->nullable()->change();
                    }
                });
            }
        }

        if (! $this->hasProductForeignKey($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)->references('id')->on('products')->nullOnDelete();
            });
        }
    }

    private function isColumnNullablePgsql(string $table, string $column): bool
    {
        $row = DB::selectOne(
            "SELECT is_nullable FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );

        return $row && strtoupper((string) $row->is_nullable) === 'YES';
    }

    private function hasProductForeignKeyWithNullOnDeletePgsql(string $table, string $column): bool
    {
        $row = DB::selectOne(
            "SELECT rc.delete_rule
             FROM information_schema.table_constraints AS tc
             JOIN information_schema.key_column_usage AS kcu
               ON tc.constraint_schema = kcu.constraint_schema
              AND tc.constraint_name = kcu.constraint_name
             JOIN information_schema.constraint_column_usage AS ccu
               ON ccu.constraint_schema = tc.constraint_schema
              AND ccu.constraint_name = tc.constraint_name
             JOIN information_schema.referential_constraints AS rc
               ON rc.constraint_schema = tc.constraint_schema
              AND rc.constraint_name = tc.constraint_name
             WHERE tc.table_schema = current_schema()
               AND tc.table_name = ?
               AND tc.constraint_type = 'FOREIGN KEY'
               AND kcu.column_name = ?
               AND ccu.table_name = 'products'
             LIMIT 1",
            [$table, $column]
        );

        return $row && strtoupper((string) $row->delete_rule) === 'SET NULL';
    }

    private function dropProductForeignKeyPgsql(string $table, string $column): void
    {
        $fkRows = DB::select(
            "SELECT tc.constraint_name
             FROM information_schema.table_constraints AS tc
             JOIN information_schema.key_column_usage AS kcu
               ON tc.constraint_schema = kcu.constraint_schema
              AND tc.constraint_name = kcu.constraint_name
             WHERE tc.table_schema = current_schema()
               AND tc.table_name = ?
               AND tc.constraint_type = 'FOREIGN KEY'
               AND kcu.column_name = ?",
            [$table, $column]
        );
        foreach ($fkRows as $fk) {
            $name = $fk->constraint_name ?? null;
            if ($name) {
                $t = $this->escapePgIdentifier($table);
                $c = $this->escapePgIdentifier($name);
                DB::statement("ALTER TABLE \"{$t}\" DROP CONSTRAINT IF EXISTS \"{$c}\"");
            }
        }
    }

    private function dropProductForeignKeyGeneric(string $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->dropProductForeignKeyPgsql($table, $column);

            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // FK inexistente ou nome legado
        }
    }

    private function hasProductForeignKey(string $table, string $column): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.table_constraints AS tc
                 JOIN information_schema.key_column_usage AS kcu
                   ON tc.constraint_schema = kcu.constraint_schema
                  AND tc.constraint_name = kcu.constraint_name
                 JOIN information_schema.constraint_column_usage AS ccu
                   ON ccu.constraint_schema = tc.constraint_schema
                  AND ccu.constraint_name = tc.constraint_name
                 WHERE tc.table_schema = current_schema()
                   AND tc.table_name = ?
                   AND tc.constraint_type = 'FOREIGN KEY'
                   AND kcu.column_name = ?
                   AND ccu.table_name = 'products'
                 LIMIT 1",
                [$table, $column]
            );
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $schema = DB::getDatabaseName();

            return (bool) DB::selectOne(
                "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME = 'products' LIMIT 1",
                [$schema, $table, $column]
            );
        }

        return false;
    }

    private function escapePgIdentifier(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    public function down(): void
    {
        // Não restaurar ON DELETE CASCADE: apagaria histórico de pedidos ao excluir produto.
    }
};
