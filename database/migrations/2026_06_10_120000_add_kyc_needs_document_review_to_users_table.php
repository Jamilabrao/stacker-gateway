<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'kyc_needs_document_review')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('kyc_needs_document_review')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'kyc_needs_document_review')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('kyc_needs_document_review');
        });
    }
};
