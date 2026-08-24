<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'identity_document_type')) {
                $table->string('identity_document_type', 16)->nullable()->after('kyc_needs_document_review');
            }
            if (! Schema::hasColumn('users', 'company_legal_nature')) {
                $table->string('company_legal_nature', 16)->nullable()->after('identity_document_type');
            }
            if (! Schema::hasColumn('users', 'kyc_requirements_version')) {
                $table->unsignedTinyInteger('kyc_requirements_version')->default(1)->after('company_legal_nature');
            }
        });

        if (Schema::hasTable('kyc_documents') && ! Schema::hasColumn('kyc_documents', 'superseded_at')) {
            Schema::table('kyc_documents', function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable()->after('size_bytes');
                $table->index(['user_id', 'kind', 'superseded_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kyc_documents') && Schema::hasColumn('kyc_documents', 'superseded_at')) {
            Schema::table('kyc_documents', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'kind', 'superseded_at']);
                $table->dropColumn('superseded_at');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['identity_document_type', 'company_legal_nature', 'kyc_requirements_version'],
                fn (string $c) => Schema::hasColumn('users', $c)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
