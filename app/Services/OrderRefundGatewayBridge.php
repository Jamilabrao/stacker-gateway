<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\Order;
use App\Support\CajuPayPaymentId;
use App\Support\GatewayPaymentCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Tenta estorno via API do adquirente quando o driver expõe refundTransaction.
 */
class OrderRefundGatewayBridge
{
    /**
     * @return array{status: string, note: ?string, error_code?: string}
     */
    public function tryRefund(Order $order): array
    {
        $gatewaySlug = $order->gateway;
        if ($gatewaySlug === null || $gatewaySlug === '') {
            return ['status' => 'skipped', 'note' => 'Pedido sem gateway registrado.'];
        }

        $tenantId = (int) $order->tenant_id;
        $credentials = GatewayPaymentCredentials::resolve($tenantId, $gatewaySlug, $order);
        if ($credentials === null) {
            return ['status' => 'skipped', 'note' => 'Credencial do gateway não encontrada.'];
        }

        $driver = GatewayRegistry::driver($gatewaySlug);
        if (! $driver || ! is_callable([$driver, 'refundTransaction'])) {
            return ['status' => 'skipped', 'note' => 'Estorno automático não implementado para este gateway; conclua no adquirente se necessário.'];
        }

        if ($gatewaySlug === 'cajupay' && ! CajuPayPaymentId::isPixPaymentMethod($order->payment_method)) {
            return [
                'status' => 'skipped',
                'note' => 'Reembolso de cartão/wallet CajuPay é confirmado via webhook; a carteira será ajustada quando o estorno for processado no adquirente.',
            ];
        }

        $paymentId = CajuPayPaymentId::fromOrder($order);
        if ($paymentId === null || $paymentId === '') {
            return ['status' => 'skipped', 'note' => 'Sem ID de pagamento CajuPay no pedido.'];
        }

        try {
            /** @var CajuPayDriver $driver */
            $result = $driver->refundTransaction($credentials, $paymentId, (float) $order->amount, (string) $order->id);
            $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
            $errorCode = is_string($result['error_code'] ?? null) ? $result['error_code'] : null;
            if ($errorCode === null && is_string($raw['error'] ?? null)) {
                $errorCode = strtolower(trim($raw['error']));
            }

            if (($result['success'] ?? false) === true) {
                if (! empty($result['pending'])) {
                    $meta = is_array($order->metadata) ? $order->metadata : [];
                    $meta['cajupay_pix_refund_status'] = $raw['status'] ?? 'submitted';
                    $meta['cajupay_pix_refund_pending'] = true;
                    $order->update(['metadata' => $meta]);

                    return [
                        'status' => 'gateway_pending',
                        'note' => $result['message'] ?? 'Reembolso PIX enviado; aguardando confirmação.',
                    ];
                }

                return ['status' => 'gateway_ok', 'note' => $result['message'] ?? null];
            }

            if ($errorCode === 'med_blocks_refund') {
                return [
                    'status' => 'blocked_med',
                    'note' => $result['message'] ?? 'Reembolso bloqueado por disputa MED aberta.',
                    'error_code' => 'med_blocks_refund',
                ];
            }

            return ['status' => 'failed', 'note' => $this->failedAcquirerNote($result['message'] ?? null, $errorCode), 'error_code' => $errorCode];
        } catch (\Throwable $e) {
            Log::warning('OrderRefundGatewayBridge: estorno API falhou.', [
                'order_id' => $order->id,
                'gateway' => $gatewaySlug,
                'message' => $e->getMessage(),
            ]);

            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'med_blocks_refund')) {
                return ['status' => 'blocked_med', 'note' => $msg, 'error_code' => 'med_blocks_refund'];
            }

            return ['status' => 'failed', 'note' => $this->communicationFailureNote($e)];
        }
    }

    private function failedAcquirerNote(?string $message, ?string $errorCode): string
    {
        $message = trim((string) $message);
        if ($message !== '') {
            return $message;
        }

        if ($errorCode) {
            return 'A adquirente recusou o evento de reembolso (código '.$errorCode.').';
        }

        return 'A adquirente não confirmou o evento de reembolso.';
    }

    private function communicationFailureNote(\Throwable $e): string
    {
        $msg = trim($e->getMessage());
        $lower = strtolower($msg);

        $isCommunicationFailure = $e instanceof \Illuminate\Http\Client\ConnectionException
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'could not resolve')
            || str_contains($lower, 'failed to connect');

        if ($isCommunicationFailure) {
            return 'A adquirente não recebeu o evento de reembolso (falha de comunicação).'
                .($msg !== '' ? ' Detalhe: '.$msg : '');
        }

        return $msg !== ''
            ? 'Erro na API da adquirente: '.$msg
            : 'A adquirente não confirmou o evento de reembolso.';
    }
}
