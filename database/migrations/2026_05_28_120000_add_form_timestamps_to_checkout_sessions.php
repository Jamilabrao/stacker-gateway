<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'form_started_at')) {
                $table->timestamp('form_started_at')->nullable()->after('step');
            }
            if (! Schema::hasColumn('checkout_sessions', 'form_filled_at')) {
                $table->timestamp('form_filled_at')->nullable()->after('form_started_at');
            }
        });

        DB::table('checkout_sessions')
            ->whereIn('step', ['form_started', 'form_filled'])
            ->whereNull('form_started_at')
            ->update(['form_started_at' => DB::raw('updated_at')]);

        DB::table('checkout_sessions')
            ->where('step', 'form_filled')
            ->whereNull('form_filled_at')
            ->update(['form_filled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'form_filled_at')) {
                $table->dropColumn('form_filled_at');
            }
            if (Schema::hasColumn('checkout_sessions', 'form_started_at')) {
                $table->dropColumn('form_started_at');
            }
        });
    }
};
