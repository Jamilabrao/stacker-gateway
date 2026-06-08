<?php

namespace App\Console\Commands;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class FlagMerchantsMissingKycDocuments extends Command
{
    protected $signature = 'kyc:flag-missing-documents {--dry-run : Apenas listar, sem alterar}';

    protected $description = 'Marca infoprodutores aprovados sem documentos KYC para revisão manual (sem interromper operação).';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'kyc_status') || ! Schema::hasTable('kyc_documents')) {
            $this->warn('Tabelas/colunas KYC não disponíveis.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $this->error('Coluna kyc_needs_document_review ausente. Execute as migrations.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $flagged = 0;

        User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('kyc_status', User::KYC_APPROVED)
            ->where('kyc_needs_document_review', false)
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$flagged, $dryRun) {
                foreach ($users as $user) {
                    $docCount = KycDocument::query()->where('user_id', $user->id)->count();
                    if ($docCount > 0) {
                        continue;
                    }

                    $this->line("{$user->id} — {$user->email} (sem documentos)");
                    if (! $dryRun) {
                        $user->forceFill(['kyc_needs_document_review' => true])->save();
                        PlatformAuditService::log('platform.kyc.flagged_missing_documents', [
                            'merchant_user_id' => $user->id,
                        ], request());
                    }
                    $flagged++;
                }
            });

        $this->info($dryRun
            ? "Encontrados {$flagged} infoprodutor(es) para sinalizar."
            : "Sinalizados {$flagged} infoprodutor(es) para revisão manual.");

        return self::SUCCESS;
    }
}
