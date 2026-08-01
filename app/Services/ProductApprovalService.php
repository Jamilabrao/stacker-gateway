<?php

namespace App\Services;

use App\Jobs\SendProductApprovedMailJob;
use App\Jobs\SendProductRejectedMailJob;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductApprovalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Fluxo de análise de produtos (aprovação ≠ ativação).
 */
class ProductApprovalService
{
    public const REASON_MIN = 20;

    public const REASON_MAX = 1000;

    public static function columnsReady(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasColumn('products', 'approval_status');
    }

    /**
     * Atributos iniciais no create (antes do save).
     *
     * @return array{approval_status: string, approval_source: string, approval_reason: null, reviewed_by: null, reviewed_at: \Illuminate\Support\Carbon|null, is_active?: bool}
     */
    public function attributesForNewProduct(bool $requestedActive = true): array
    {
        if (! self::columnsReady()) {
            return [];
        }

        if (ProductApprovalSettings::autoApproveEnabled()) {
            return [
                'approval_status' => Product::APPROVAL_APPROVED,
                'approval_source' => Product::APPROVAL_SOURCE_AUTOMATIC,
                'approval_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => now(),
            ];
        }

        return [
            'approval_status' => Product::APPROVAL_PENDING,
            'approval_source' => null,
            'approval_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'is_active' => false,
        ];
    }

    public function afterCreated(Product $product, ?Request $request = null): void
    {
        if (! self::columnsReady()) {
            return;
        }

        if ($product->approval_status === Product::APPROVAL_APPROVED
            && $product->approval_source === Product::APPROVAL_SOURCE_AUTOMATIC) {
            PlatformAuditService::log('products.auto_approved', [
                'product_id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'product_name' => $product->name,
            ], $request);
        } elseif ($product->approval_status === Product::APPROVAL_PENDING) {
            PlatformAuditService::log('products.submitted_for_review', [
                'product_id' => $product->id,
                'tenant_id' => $product->tenant_id,
                'product_name' => $product->name,
            ], $request);
        }
    }

    public function approve(Product $product, User $admin, ?Request $request = null): Product
    {
        if (! self::columnsReady()) {
            throw new InvalidArgumentException('Execute as migrações do banco para usar a aprovação de produtos.');
        }

        return DB::transaction(function () use ($product, $admin, $request) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status === Product::APPROVAL_APPROVED) {
                throw new InvalidArgumentException('Este produto já foi aprovado.');
            }

            $from = $locked->approval_status;
            $locked->forceFill([
                'approval_status' => Product::APPROVAL_APPROVED,
                'approval_source' => Product::APPROVAL_SOURCE_MANUAL,
                'approval_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            PlatformAuditService::log('products.approved', [
                'product_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'product_name' => $locked->name,
                'from' => $from,
                'to' => Product::APPROVAL_APPROVED,
                'reviewed_by' => $admin->id,
            ], $request);

            SendProductApprovedMailJob::dispatch($locked->id);

            return $locked->fresh();
        });
    }

    public function reject(Product $product, User $admin, string $reason, ?Request $request = null): Product
    {
        if (! self::columnsReady()) {
            throw new InvalidArgumentException('Execute as migrações do banco para usar a aprovação de produtos.');
        }

        $reason = trim($reason);
        $len = mb_strlen($reason);
        if ($len < self::REASON_MIN) {
            throw new InvalidArgumentException('Informe o motivo com pelo menos '.self::REASON_MIN.' caracteres.');
        }
        if ($len > self::REASON_MAX) {
            throw new InvalidArgumentException('O motivo deve ter no máximo '.self::REASON_MAX.' caracteres.');
        }

        return DB::transaction(function () use ($product, $admin, $reason, $request) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status === Product::APPROVAL_REJECTED) {
                throw new InvalidArgumentException('Este produto já foi rejeitado.');
            }

            $from = $locked->approval_status;
            $locked->forceFill([
                'approval_status' => Product::APPROVAL_REJECTED,
                'approval_source' => Product::APPROVAL_SOURCE_MANUAL,
                'approval_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'is_active' => false,
            ])->save();

            PlatformAuditService::log('products.rejected', [
                'product_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'product_name' => $locked->name,
                'from' => $from,
                'to' => Product::APPROVAL_REJECTED,
                'reviewed_by' => $admin->id,
                'reason' => $reason,
            ], $request);

            SendProductRejectedMailJob::dispatch($locked->id);

            return $locked->fresh();
        });
    }

    public function resubmit(Product $product, ?Request $request = null): Product
    {
        if (! self::columnsReady()) {
            throw new InvalidArgumentException('Execute as migrações do banco para usar a aprovação de produtos.');
        }

        return DB::transaction(function () use ($product, $request) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== Product::APPROVAL_REJECTED) {
                throw new InvalidArgumentException('Somente produtos não aprovados podem ser reenviados para análise.');
            }

            $locked->forceFill([
                'approval_status' => Product::APPROVAL_PENDING,
                'approval_source' => null,
                'is_active' => false,
                // Mantém approval_reason e reviewed_* como histórico visível até nova decisão.
            ])->save();

            PlatformAuditService::log('products.resubmitted', [
                'product_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'product_name' => $locked->name,
                'previous_reason' => $locked->approval_reason,
            ], $request);

            return $locked->fresh();
        });
    }

    public function setActiveByAdmin(Product $product, bool $active, User $admin, ?Request $request = null): Product
    {
        if (! self::columnsReady()) {
            throw new InvalidArgumentException('Execute as migrações do banco para usar a aprovação de produtos.');
        }

        return DB::transaction(function () use ($product, $active, $admin, $request) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($active && $locked->approval_status !== Product::APPROVAL_APPROVED) {
                throw new InvalidArgumentException('Este produto ainda não pode ser ativado porque não foi aprovado.');
            }

            $from = (bool) $locked->is_active;
            $locked->forceFill(['is_active' => $active])->save();

            PlatformAuditService::log($active ? 'products.activated_by_admin' : 'products.deactivated_by_admin', [
                'product_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'product_name' => $locked->name,
                'from' => $from ? '1' : '0',
                'to' => $active ? '1' : '0',
                'admin_id' => $admin->id,
            ], $request);

            return $locked->fresh();
        });
    }

    /**
     * Impede seller de ativar produto não aprovado.
     */
    public function guardSellerActivation(Product $product, bool $wantsActive): void
    {
        if (! self::columnsReady() || ! $wantsActive) {
            return;
        }

        if ($product->approval_status !== Product::APPROVAL_APPROVED) {
            throw new InvalidArgumentException('Este produto ainda não pode ser ativado porque não foi aprovado.');
        }
    }

    /**
     * Props seguras para o painel do seller (sem dados internos de auditoria).
     *
     * @return array<string, mixed>
     */
    public function sellerFacingStatus(Product $product): array
    {
        if (! self::columnsReady()) {
            return [
                'status' => Product::APPROVAL_APPROVED,
                'label' => 'Aprovado',
                'description' => 'Este produto foi aprovado e pode ser disponibilizado para venda, desde que esteja ativo.',
                'reason' => null,
                'can_resubmit' => false,
            ];
        }

        return match ($product->approval_status) {
            Product::APPROVAL_PENDING => [
                'status' => Product::APPROVAL_PENDING,
                'label' => 'Em análise',
                'description' => 'Este produto está sendo analisado pela equipe da plataforma e ainda não está disponível para venda.',
                'reason' => null,
                'can_resubmit' => false,
            ],
            Product::APPROVAL_REJECTED => [
                'status' => Product::APPROVAL_REJECTED,
                'label' => 'Não aprovado',
                'description' => 'Este produto não foi aprovado. Consulte o motivo informado pela equipe da plataforma.',
                'reason' => $product->approval_reason,
                'can_resubmit' => true,
            ],
            default => [
                'status' => Product::APPROVAL_APPROVED,
                'label' => 'Aprovado',
                'description' => 'Este produto foi aprovado e pode ser disponibilizado para venda, desde que esteja ativo.',
                'reason' => null,
                'can_resubmit' => false,
            ],
        };
    }
}
