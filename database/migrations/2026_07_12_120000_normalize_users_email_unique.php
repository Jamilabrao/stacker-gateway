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

        $seen = [];
        $rows = DB::table('users')->select('id', 'email')->orderBy('id')->get();

        foreach ($rows as $row) {
            $normalized = strtolower(trim((string) $row->email));
            if ($normalized === '') {
                continue;
            }

            if (isset($seen[$normalized])) {
                $duplicateId = (int) $row->id;
                $resolved = $this->deduplicateEmail((string) $row->email, $duplicateId);

                Log::warning('normalize_users_email: duplicate email after normalization — suffix applied', [
                    'email' => $normalized,
                    'user_id' => $duplicateId,
                    'kept_user_id' => $seen[$normalized],
                    'resolved_email' => $resolved,
                ]);

                DB::table('users')->where('id', $duplicateId)->update(['email' => $resolved]);

                continue;
            }

            $seen[$normalized] = (int) $row->id;

            if ((string) $row->email !== $normalized) {
                DB::table('users')->where('id', $row->id)->update(['email' => $normalized]);
            }
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
