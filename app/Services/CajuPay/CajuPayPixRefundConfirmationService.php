<?php

namespace App\Services\CajuPay;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Jobs\PollCajuPayPixRefundJob;
use App\Models\Order;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayPaymentId;
use App\Support\GatewayPaymentCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Após o seller pedir reembolso: carteira já debitada (refund_pending).
 * Efetiva no Stacker quando a CajuPay mostrar pagamento cancelado/reembolsado
 * ou o objeto pix-refund em devolvido.
 */
class CajuPayPixRefundConfirmationService
{
    public static function isCajuPixOrder(Order $order): bool
    {
        $gateway = strtolower(trim((string) ($order->gateway ?? '')));

        return $gateway === 'cajupay' && CajuPayPaymentId::isPixPaymentMethod($order->payment_method);
    }

    /**
     * Debita o seller, marca aguardando e tenta confirmar na CajuPay.
     *
     * @param  array<string, mixed>|null  $manualRefundMeta
     * @return 'refunded'|'refund_pending'
     */
    public function lockWalletAndAwait(Order $order, ?array $manualRefundMeta, string $debitReason): string
    {
        $this->markPendingMeta($order);

        $fresh = $order->fresh();
        if ($fresh !== null && in_array($fresh->status, ['completed', 'disputed'], true)) {
            PlatformOrderAdminService::beginPendingGatewayRefund($fresh, $manualRefundMeta, $debitReason);
        }

        $pending = $order->fresh();
        try {
            if ($pending !== null && $this->confirmIfRemoteCancelled($pending)) {
                return 'refunded';
            }
        } catch (\Throwable $e) {
            Log::warning('CajuPayPixRefundConfirmation: confirmação imediata falhou; pedido permanece aguardando.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        PollCajuPayPixRefundJob::dispatch($order->id)->delay(now()->addSeconds(5));

        return 'refund_pending';
    }

    public function confirmIfRemoteCancelled(Order $order): bool
    {
        $order = $order->fresh() ?? $order;
        if ($order->status === 'refunded') {
            return true;
        }
        if (! in_array($order->status, ['refund_pending', 'completed', 'disputed'], true)) {
            return false;
        }

        $paymentStatus = $this->remotePaymentStatus($order);
        if (in_array($paymentStatus, ['cancelled', 'canceled', 'refunded'], true)) {
            $this->finalize($order, (string) $paymentStatus);

            return true;
        }

        $refundStatus = $this->remotePixRefundStatus($order);
        if ($refundStatus === 'devolvido') {
            $this->finalize($order, 'devolvido');

            return true;
        }

        return false;
    }

    public function isRemoteCancelledOrRefunded(Order $order): bool
    {
        $paymentStatus = $this->remotePaymentStatus($order);
        if (in_array($paymentStatus, ['cancelled', 'canceled', 'refunded'], true)) {
            return true;
        }

        return $this->remotePixRefundStatus($order) === 'devolvido';
    }

    private function remotePaymentStatus(Order $order): ?string
    {
        $resolved = $this->driverAndCredentials($order);
        if ($resolved === null) {
            return null;
        }
        [$driver, $credentials, $paymentId] = $resolved;

        try {
            return $driver->getPixPaymentStatus($paymentId, $credentials);
        } catch (\Throwable $e) {
            Log::debug('CajuPayPixRefundConfirmation: consulta pagamento falhou', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function remotePixRefundStatus(Order $order): ?string
    {
        $resolved = $this->driverAndCredentials($order);
        if ($resolved === null) {
            return null;
        }
        [$driver, $credentials, $paymentId] = $resolved;

        try {
            $body = $driver->getPixRefund($credentials, $paymentId);
        } catch (\Throwable $e) {
            Log::debug('CajuPayPixRefundConfirmation: consulta pix-refund falhou', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $status = strtolower(trim((string) ($body['status'] ?? '')));

        return $status !== '' ? $status : null;
    }

    /**
     * @return array{0: CajuPayDriver, 1: array<string, mixed>, 2: string}|null
     */
    private function driverAndCredentials(Order $order): ?array
    {
        $paymentId = CajuPayPaymentId::fromOrder($order);
        if ($paymentId === null) {
            return null;
        }

        $credentials = GatewayPaymentCredentials::resolve((int) $order->tenant_id, 'cajupay', $order);
        if ($credentials === null) {
            return null;
        }

        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return null;
        }

        return [$driver, $credentials, $paymentId];
    }

    private function markPendingMeta(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['cajupay_pix_refund_pending'] = true;
        if (! isset($meta['cajupay_pix_refund_status']) || $meta['cajupay_pix_refund_status'] === '') {
            $meta['cajupay_pix_refund_status'] = 'submitted';
        }
        $order->update(['metadata' => $meta]);
    }

    private function finalize(Order $order, string $remoteStatus): void
    {
        $order = $order->fresh() ?? $order;
        $meta = is_array($order->metadata) ? $order->metadata : [];
        unset($meta['cajupay_pix_refund_pending']);
        $meta['cajupay_pix_refund_status'] = $remoteStatus;
        $order->update(['metadata' => $meta]);

        PlatformOrderAdminService::applyGatewayRefund($order->fresh() ?? $order);
    }
}
