<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_integrax_settings', function (Blueprint $table) {
            $table->json('cart_recovery_steps')->nullable()->after('message_pix_generated');
        });

        Schema::table('integrax_sms_dispatches', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence_step')->nullable()->after('event_type');
            $table->index(['checkout_session_id', 'event_type', 'sequence_step'], 'integrax_dispatches_session_step_idx');
        });
    }

    public function down(): void
    {
        Schema::table('integrax_sms_dispatches', function (Blueprint $table) {
            $table->dropIndex('integrax_dispatches_session_step_idx');
            $table->dropColumn('sequence_step');
        });

        Schema::table('platform_integrax_settings', function (Blueprint $table) {
            $table->dropColumn('cart_recovery_steps');
        });
    }
};
