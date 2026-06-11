<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('translations:sync-config');
    }

    public function down(): void
    {
        // Sem rollback: textos no banco podem ter sido editados manualmente.
    }
};
