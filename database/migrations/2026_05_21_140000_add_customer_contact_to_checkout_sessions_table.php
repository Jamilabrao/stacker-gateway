<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        // Sem ->after(): compatível com PostgreSQL (AFTER só existe no MySQL).
        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_sessions', 'cpf')) {
                $table->string('cpf', 14)->nullable();
            }
            if (! Schema::hasColumn('checkout_sessions', 'phone')) {
                $table->string('phone', 24)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return;
        }

        Schema::table('checkout_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_sessions', 'phone')) {
                $table->dropColumn('phone');
            }
            if (Schema::hasColumn('checkout_sessions', 'cpf')) {
                $table->dropColumn('cpf');
            }
        });
    }
};
