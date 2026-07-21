<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\MedDispute;
use App\Models\Order;
use App\Services\Med\MedPolicyService;
use App\Services\Med\MedResolutionService;
use App\Services\MedEmailNotifications;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayPaymentId;
use Illuminate\Http\UploadedFile;

class CajuPayMedService
{
    public function __construct(
        protected MedPolicyService $policy,
        protected MedResolutionService $resolution,
        protected MedEmailNotifications $notifications,
    ) {}

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

        $responsibleParty = $this->policy->responsiblePartyForOrder($order);
        $reason = trim((string) ($object['reason'] ?? $object['reason_description'] ?? $object['description'] ?? ''));
        $reasonCode = trim((string) ($object['reason_code'] ?? ''));

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $disputeId],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $responsibleParty,
                'cajupay_payment_id' => $paymentId !== '' ? $paymentId : CajuPayPaymentId::fromOrder($order),
                'status' => MedDispute::STATUS_OPEN,
                'outcome' => null,
                'amount_cents' => (int) ($object['amount_cents'] ?? 0),
                'currency' => (string) ($object['currency'] ?? 'BRL'),
                'txid' => isset($object['txid']) ? (string) $object['txid'] : null,
                'reason' => $reason !== '' ? $reason : null,
                'reason_code' => $reasonCode !== '' ? $reasonCode : null,
                'opened_at' => now(),
                'metadata' => ['webhook' => $object],
            ]
        );

        if ($this->policy->shouldHoldTenantBalance($dispute) && ! in_array($order->fresh()->status, ['disputed'], true)) {
            try {
                PlatformOrderAdminService::markDisputed($order->fresh());
            } catch (\InvalidArgumentException) {
                //
            }
        }

        if ($dispute->wasRecentlyCreated) {
            $this->notifications->medOpened($dispute->fresh());
        }

        return $dispute->fresh();
    }

    /**
     * Checkout/card disputed — plataforma gerencia, sem retenção no infoprodutor.
     *
     * @param  array<string, mixed>  $context
     */
    public function syncOpenedFromCheckoutDispute(Order $order, array $context = []): MedDispute
    {
        $disputeId = 'checkout-order-'.$order->id;
        $reason = trim((string) ($context['reason'] ?? $context['gateway_event'] ?? 'checkout.payment.disputed'));

        $dispute = MedDispute::query()->updateOrCreate(
            ['cajupay_dispute_id' => $disputeId],
            [
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => MedDispute::PARTY_PLATFORM,
                'cajupay_payment_id' => CajuPayPaymentId::fromOrder($order),
                'status' => MedDispute::STATUS_OPEN,
                'outcome' => null,
                'amount_cents' => (int) round(((float) $order->amount) * 100),
                'currency' => 'BRL',
                'reason' => $reason !== '' ? $reason : null,
                'opened_at' => now(),
                'metadata' => ['checkout_dispute' => $context],
            ]
        );

        if ($dispute->wasRecentlyCreated) {
            $this->notifications->medOpened($dispute->fresh());
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
            'won', 'merchant_won', 'resolved_won' => MedDispute::STATUS_RESOLVED_WON,
            'lost', 'payer_won', 'customer_won', 'resolved_lost' => MedDispute::STATUS_RESOLVED_LOST,
            'cancelled', 'canceled', 'resolved_cancelled', 'resolved_canceled' => MedDispute::STATUS_CANCELLED,
            default => MedDispute::STATUS_RESOLVED_WON,
        };

        $walletOutcome = match ($mappedStatus) {
            MedDispute::STATUS_RESOLVED_LOST => 'lost',
            MedDispute::STATUS_CANCELLED => 'cancelled',
            default => 'won',
        };

        $responsibleParty = $this->policy->responsiblePartyForOrder($order);

        if ($dispute === null) {
            $dispute = MedDispute::query()->create([
                'order_id' => $order->id,
                'tenant_id' => (int) $order->tenant_id,
                'responsible_party' => $responsibleParty,
                'cajupay_dispute_id' => $disputeId !== '' ? $disputeId : 'unknown-'.$order->id,
                'cajupay_payment_id' => CajuPayPaymentId::fromOrder($order),
                'status' => $mappedStatus,
                'outcome' => $walletOutcome,
                'amount_cents' => (int) ($object['amount_cents'] ?? 0),
                'currency' => (string) ($object['currency'] ?? 'BRL'),
                'txid' => isset($object['txid']) ? (string) $object['txid'] : null,
                'resolved_at' => now(),
                'metadata' => ['webhook_resolved' => $object],
            ]);
        } else {
            $dispute->update([
                'status' => $mappedStatus,
                'outcome' => $walletOutcome,
                'resolved_at' => now(),
                'metadata' => array_merge(is_array($dispute->metadata) ? $dispute->metadata : [], [
                    'webhook_resolved' => $object,
                ]),
            ]);
        }

        $this->resolution->applyWalletOutcome($dispute->fresh(), $walletOutcome);
        $this->notifications->medResolved($dispute->fresh());

        return $dispute->fresh();
    }

    public function openCountForTenant(int $tenantId): int
    {
        return MedDispute::query()
            ->forTenant($tenantId)
            ->tenantManaged()
            ->open()
            ->count();
    }

    /**
     * @return array{driver: CajuPayDriver, credentials: array<string, mixed>}|null
     */
    public function resolveDriverForTenant(int $tenantId): ?array
    {
        $account = app(CajuPayAccountResolver::class)->resolveForTenant($tenantId);
        if ($account === null) {
            return null;
        }
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return null;
        }

        return [
            'driver' => $driver,
            'credentials' => $account->getDecryptedCredentials(),
        ];
    }

    /**
     * @return array{driver: CajuPayDriver, credentials: array<string, mixed>}|null
     */
    public function resolveDriverForOrder(Order $order): ?array
    {
        $account = app(CajuPayAccountResolver::class)->resolveForOrder($order);
        if ($account === null) {
            return null;
        }
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return null;
        }

        return [
            'driver' => $driver,
            'credentials' => $account->getDecryptedCredentials(),
        ];
    }

    /**
     * @return list<MedDispute>
     */
    public function listForTenant(int $tenantId, ?string $statusFilter = null): array
    {
        $q = MedDispute::query()
            ->forTenant($tenantId)
            ->tenantManaged()
            ->with(['order.product'])
            ->orderByDesc('id');

        if ($statusFilter === 'open') {
            $q->open();
        } elseif ($statusFilter === 'resolved') {
            $q->whereNotIn('status', [MedDispute::STATUS_OPEN, MedDispute::STATUS_DEFENSE_SUBMITTED]);
        }

        $items = $q->limit(100)->get();
        if ($statusFilter === 'open' || $statusFilter === null) {
            $this->reconcileOpenDisputesFromRemote($items);
            $items = $items->map(fn (MedDispute $d) => $d->fresh() ?? $d)
                ->filter(function (MedDispute $d) use ($statusFilter) {
                    if ($statusFilter === 'open') {
                        return $d->isOpen();
                    }

                    return true;
                })
                ->values();
        }

        return $items->all();
    }

    public function getForTenant(int $tenantId, MedDispute $dispute): MedDispute
    {
        if ((int) $dispute->tenant_id !== $tenantId) {
            throw new \InvalidArgumentException('Disputa não encontrada.');
        }

        if (! $dispute->isTenantManaged()) {
            throw new \InvalidArgumentException('Disputa não encontrada.');
        }

        $dispute->load(['order.product', 'order.user']);

        if ($dispute->isOpen()) {
            $dispute = $this->reconcileFromRemoteIfNeeded($dispute);
            $dispute->load(['order.product', 'order.user']);
        }

        $resolved = $this->resolveDriverForOrder($dispute->order) ?? $this->resolveDriverForTenant($tenantId);
        if ($resolved !== null && $dispute->cajupay_dispute_id && ! str_starts_with($dispute->cajupay_dispute_id, 'checkout-order-')) {
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

        $dispute->loadMissing('order');
        $resolved = $this->resolveDriverForOrder($dispute->order) ?? $this->resolveDriverForTenant((int) $dispute->tenant_id);
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

        if ($dispute->cajupay_dispute_id && ! str_starts_with($dispute->cajupay_dispute_id, 'checkout-order-')) {
            $resolved['driver']->submitMedDefense(
                $resolved['credentials'],
                $dispute->cajupay_dispute_id,
                $text,
                $attachments
            );
        }

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

    public function orderHasOpenTenantMed(Order $order): bool
    {
        return MedDispute::query()
            ->where('order_id', $order->id)
            ->tenantManaged()
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

    /**
     * Resolve pedido para webhooks MED (API PIX incluso): payment id ou disputa já aberta localmente.
     *
     * @param  array<string, mixed>  $object
     */
    public static function findOrderForMedWebhook(array $object): ?Order
    {
        $byPayment = self::findOrderForPixWebhook($object);
        if ($byPayment !== null) {
            return $byPayment;
        }

        $disputeId = trim((string) ($object['med_dispute_id'] ?? $object['dispute_id'] ?? ''));
        if ($disputeId === '' && isset($object['id']) && is_string($object['id'])) {
            // Só usa `id` se parecer disputa MED (não confundir com payment id genérico sem contexto).
            $disputeId = trim($object['id']);
        }
        if ($disputeId === '') {
            return null;
        }

        $dispute = MedDispute::query()
            ->where('cajupay_dispute_id', $disputeId)
            ->with('order')
            ->first();

        return $dispute?->order;
    }

    /**
     * Se a Caju já encerrou a MED e o webhook não atualizou o local, concilia via API.
     */
    public function reconcileFromRemoteIfNeeded(MedDispute $dispute): MedDispute
    {
        if (! $dispute->isOpen()) {
            return $dispute;
        }

        $remoteId = trim((string) ($dispute->cajupay_dispute_id ?? ''));
        if ($remoteId === '' || str_starts_with($remoteId, 'checkout-order-')) {
            return $dispute;
        }

        $dispute->loadMissing('order');
        $order = $dispute->order;
        if ($order === null) {
            return $dispute;
        }

        $resolved = $this->resolveDriverForOrder($order) ?? $this->resolveDriverForTenant((int) $dispute->tenant_id);
        if ($resolved === null) {
            return $dispute;
        }

        try {
            $remote = $resolved['driver']->getMedDispute($resolved['credentials'], $remoteId);
        } catch (\Throwable) {
            return $dispute;
        }

        $statusRaw = strtolower(trim((string) ($remote['status'] ?? '')));
        $outcome = strtolower(trim((string) ($remote['outcome'] ?? '')));

        if ($outcome === '' && str_starts_with($statusRaw, 'resolved_')) {
            $outcome = str_replace('resolved_', '', $statusRaw);
        }

        $terminal = in_array($statusRaw, [
            'resolved_won', 'resolved_lost', 'cancelled', 'canceled', 'won', 'lost',
        ], true) || in_array($outcome, ['won', 'lost', 'cancelled', 'canceled', 'merchant_won', 'payer_won'], true);

        if (! $terminal) {
            return $dispute;
        }

        $object = array_merge(is_array($remote) ? $remote : [], [
            'med_dispute_id' => $remoteId,
            'status' => $statusRaw !== '' ? $statusRaw : ('resolved_'.($outcome !== '' ? $outcome : 'won')),
            'outcome' => $outcome !== '' ? $outcome : (str_contains($statusRaw, 'lost') ? 'lost' : (str_contains($statusRaw, 'cancel') ? 'cancelled' : 'won')),
        ]);

        return $this->syncResolvedFromWebhook($order, $object);
    }

    /**
     * @param  iterable<MedDispute>  $disputes
     */
    public function reconcileOpenDisputesFromRemote(iterable $disputes): void
    {
        foreach ($disputes as $dispute) {
            if (! $dispute instanceof MedDispute || ! $dispute->isOpen()) {
                continue;
            }
            try {
                $this->reconcileFromRemoteIfNeeded($dispute);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
