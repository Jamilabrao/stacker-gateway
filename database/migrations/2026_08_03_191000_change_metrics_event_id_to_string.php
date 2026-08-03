<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metrics_events')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE metrics_events ALTER COLUMN event_id TYPE varchar(128) USING event_id::text');
        } else {
            Schema::table('metrics_events', function (Blueprint $table) {
                $table->string('event_id', 128)->change();
            });
        }
    }

    public function down(): void
    {
        // Intencional: não reverte para uuid (IDs compostos não cabem).
    }
};
