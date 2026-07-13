<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'email')) {
            return;
        }

        // Passo 1: renomear duplicatas (mantém o menor id) antes de normalizar casing.
        // Se normalizar casing primeiro, UPDATE no id menor colide com o id maior que já está em minúsculas.
        $duplicateIds = collect(DB::select("
            WITH ranked AS (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY LOWER(TRIM(email)) ORDER BY id) AS rn
                FROM users
                WHERE TRIM(email) <> ''
            )
            SELECT id FROM ranked WHERE rn > 1 ORDER BY id
        "))->pluck('id');

        foreach ($duplicateIds as $duplicateId) {
            $row = DB::table('users')->where('id', $duplicateId)->first(['id', 'email']);
            if ($row === null) {
                continue;
            }

            $normalized = strtolower(trim((string) $row->email));
            $resolved = $this->deduplicateEmail((string) $row->email, (int) $row->id);

            Log::warning('normalize_users_email: duplicate email — suffix applied before casing normalization', [
                'email' => $normalized,
                'user_id' => (int) $row->id,
                'resolved_email' => $resolved,
            ]);

            DB::table('users')->where('id', $row->id)->update(['email' => $resolved]);
        }

        // Passo 2: normalizar casing nos e-mails restantes.
        $rows = DB::table('users')->select('id', 'email')->orderBy('id')->get();

        foreach ($rows as $row) {
            $normalized = strtolower(trim((string) $row->email));
            if ($normalized === '' || (string) $row->email === $normalized) {
                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $normalized]);
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $indexExists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = ANY (current_schemas(false)) AND indexname = 'users_email_lower_unique'"
        );
        if ($indexExists !== null) {
            return;
        }

        try {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['email']);
            });
        } catch (\Throwable) {
            // Índice único legado pode ter outro nome; segue com índice funcional.
        }

        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');
    }

    /**
     * Garante e-mail único quando já existe outra conta com o mesmo endereço (case-insensitive).
     */
    private function deduplicateEmail(string $email, int $userId): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        if ($at === false) {
            return strtolower($email).'+dup'.$userId;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        return strtolower($local).'+dup'.$userId.'@'.strtolower($domain);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');

            $uniqueExists = DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE schemaname = ANY (current_schemas(false)) AND indexname = 'users_email_unique'"
            );
            if ($uniqueExists === null) {
                Schema::table('users', function (Blueprint $table) {
                    $table->unique('email');
                });
            }
        }
    }
};
