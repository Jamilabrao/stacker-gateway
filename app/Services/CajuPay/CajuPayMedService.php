<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\MedDispute;
use App\Models\Order;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayPaymentId;
use Illuminate\Http\UploadedFile;
class CajuPayMedService
{
    /**
     * @param  array<string, mixed>  $object  data.object do webhook
     */
    public function syncOpenedFromWebhook(Order $order, array $object): MedDispute
    {
        $disputeId = trim((string) ($object['med_dispute_id'] ?? $object['id'] ?? ''));
        if ($disputeId === '') {
            throw new \InvalidArgumentException('med_dispute_id ausente no webhook.');
        }

        $paymentId = CajuPayPaymentId::pickFromWebhookObject($object);
        if ($paymentId !== '') {
            CajuPayPaymentId::persistOnOrder($order, $paymentId);
        }

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $disputeId],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'cajupay_payment_id' => $paymentId !== '' ? $paymentId : CajuPayPaymentId::fromOrder($order),
                'status' => MedDispute::STATUS_OPEN,
                'outcome' => null,
                'amount_cents' => (int) ($object['amount_cents'] ?? 0),
                'currency' => (string) ($object['currency'] ?? 'BRL'),
                'txid' => isset($object['txid']) ? (string) $object['txid'] : null,
                'opened_at' => now(),
                'metadata' => ['webhook' => $object],
            ]
        );

        if (! in_array($order->fresh()->status, ['disputed'], true)) {
            try {
                PlatformOrderAdminService::markDisputed($order->fresh());
            } catch (\InvalidArgumentException) {
                //
            }
        }

        return $dispute->fresh();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public function syncResolvedFromWebhook(Order $order, array $object): MedDispute
    {
        $disputeId = trim((string) ($object['med_dispute_id'] ?? $object['id'] ?? ''));
        $outcome = strtolower(trim((string) ($object['outcome'] ?? '')));
        $statusRaw = strtolower(trim((string) ($object['status'] ?? '')));

        if ($outcome === '' && str_starts_with($statusRaw, 'resolved_')) {
            $outcome = str_replace('resolved_', '', $statusRaw);
        }

        $dispute = $disputeId !== ''
            ? MedDispute::query()->where('cajupay_dispute_id', $disputeId)->first()
            : null;

        if ($dispute === null) {
            $dispute = MedDispute::query()
                ->where('order_id', $order->id)
                ->open()
                ->latest('id')
                ->first();
        }

        $mappedStatus = match ($outcome) {
            'won' => MedDispute::STATUS_RESOLVED_WON,
            'lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled' => MedDispute::STATUS_CANCELLED,
            default => MedDispute::STATUS_RESOLVED_WON,
        };

        if ($dispute === null) {
            $dispute = MedDispute::query()->create([
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'cajupay_dispute_id' => $disputeId !== '' ? $disputeId : 'unknown-'.$order->id,
                'cajupay_payment_id' => CajuPayPaymentId::fromOrder($order),
                'status' => $mappedStatus,
                'outcome' => $outcome,
                'amount_cents' => (int) ($object['amount_cents'] ?? 0),
                'currency' => (string) ($object['currency'] ?? 'BRL'),
                'txid' => isset($object['txid']) ? (string) $object['txid'] : null,
                'resolved_at' => now(),
                'metadata' => ['webhook_resolved' => $object],
            ]);
        } else {
            $dispute->update([
                'status' => $mappedStatus,
                'outcome' => $outcome !== '' ? $outcome : $dispute->outcome,
                'resolved_at' => now(),
                'metadata' => array_merge(is_array($dispute->metadata) ? $dispute->metadata : [], [
                    'webhook_resolved' => $object,
                ]),
            ]);
        }

        $freshOrder = $order->fresh();

        if ($outcome === 'lost') {
            if (in_array($freshOrder->status, ['completed', 'disputed'], true)) {
                PlatformOrderAdminService::refundPaidOrDisputed($freshOrder);
            }
        } elseif (in_array($outcome, ['won', 'cancelled'], true)) {
            if ($freshOrder->status === 'disputed') {
                PlatformOrderAdminService::releaseMedHoldAndComplete($freshOrder);
            }
        }

        return $dispute->fresh();
    }

    public function openCountForTenant(int $tenantId): int
    {
        return MedDispute::query()
            ->forTenant($tenantId)
            ->open()
            ->count();
    }

    /**
     * @return array{driver: CajuPayDriver, credentials: array<string, mixed>}|null
     */
    public function resolveDriverForTenant(int $tenantId): ?array
    {
        $credential = GatewayCredential::resolveForPayment($tenantId, 'cajupay');
        if (! $credential) {
            return null;
        }
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return null;
        }

        return [
            'driver' => $driver,
            'credentials' => $credential->getDecryptedCredentials(),
        ];
    }

    /**
     * @return list<MedDispute>
     */
    public function listForTenant(int $tenantId, ?string $statusFilter = null): array
    {
        $q = MedDispute::query()
            ->forTenant($tenantId)
            ->with(['order.product'])
            ->orderByDesc('id');

        if ($statusFilter === 'open') {
            $q->open();
        } elseif ($statusFilter === 'resolved') {
            $q->whereNotIn('status', [MedDispute::STATUS_OPEN, MedDispute::STATUS_DEFENSE_SUBMITTED]);
        }

        return $q->limit(100)->get()->all();
    }

    public function getForTenant(int $tenantId, MedDispute $dispute): MedDispute
    {
        if ((int) $dispute->tenant_id !== $tenantId) {
            throw new \InvalidArgumentException('Disputa não encontrada.');
        }

        $dispute->load(['order.product', 'order.user']);

        $resolved = $this->resolveDriverForTenant($tenantId);
        if ($resolved !== null) {
            try {
                $remote = $resolved['driver']->getMedDispute($resolved['credentials'], $dispute->cajupay_dispute_id);
                $dispute->setAttribute('remote_detail', $remote);
            } catch (\Throwable) {
                $dispute->setAttribute('remote_detail', null);
            }
        }

        return $dispute;
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function submitDefense(MedDispute $dispute, string $text, array $attachments = []): MedDispute
    {
        if (! $dispute->isOpen()) {
            throw new \InvalidArgumentException('Esta disputa não está aberta para defesa.');
        }

        $resolved = $this->resolveDriverForTenant((int) $dispute->tenant_id);
        if ($resolved === null) {
            throw new \RuntimeException('Credencial CajuPay não configurada.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Informe o texto da defesa.');
        }

        if (count($attachments) > 10) {
            throw new \InvalidArgumentException('Máximo de 10 anexos.');
        }

        foreach ($attachments as $file) {
            if ($file->getSize() > 8 * 1024 * 1024) {
                throw new \InvalidArgumentException('Cada anexo deve ter no máximo 8 MiB.');
            }
        }

        $resolved['driver']->submitMedDefense(
            $resolved['credentials'],
            $dispute->cajupay_dispute_id,
            $text,
            $attachments
        );

        $dispute->update([
            'defense_text' => $text,
            'defended_at' => now(),
            'status' => MedDispute::STATUS_DEFENSE_SUBMITTED,
        ]);

        return $dispute->fresh();
    }

    public function orderHasOpenMed(Order $order): bool
    {
        return MedDispute::query()
            ->where('order_id', $order->id)
            ->open()
            ->exists();
    }

    public static function findOrderForPixWebhook(array $object): ?Order
    {
        $paymentId = CajuPayPaymentId::pickFromWebhookObject($object);
        if ($paymentId !== '') {
            $byGateway = Order::query()
                ->where('gateway', 'cajupay')
                ->where('gateway_id', $paymentId)
                ->first();
            if ($byGateway !== null) {
                return $byGateway;
            }

            $byMeta = Order::query()
                ->where('metadata->cajupay_payment_id', $paymentId)
                ->first();
            if ($byMeta !== null) {
                return $byMeta;
            }
        }

        $clientRefundId = trim((string) ($object['client_refund_id'] ?? ''));
        if (preg_match('/order-(\d+)-refund/', $clientRefundId, $m)) {
            return Order::query()->find((int) $m[1]);
        }

        return null;
    }
}
