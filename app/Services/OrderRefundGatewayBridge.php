<?php

namespace App\Services;

use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\Cielo\CieloDriver;
use App\Gateways\GatewayRegistry;
use App\Gateways\Versell\VersellDriver;
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

        return match ($gatewaySlug) {
            'cajupay' => $this->tryCajuPayRefund($driver, $order, $credentials),
            'versell' => $this->tryVersellRefund($driver, $order, $credentials),
            'cielo' => $this->tryCieloRefund($driver, $order, $credentials),
            default => ['status' => 'skipped', 'note' => 'Estorno automático não implementado para este gateway; conclua no adquirente se necessário.'],
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{status: string, note: ?string, error_code?: string}
     */
    private function tryCajuPayRefund(object $driver, Order $order, array $credentials): array
    {
        if (! CajuPayPaymentId::isPixPaymentMethod($order->payment_method)) {
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

            return $this->mapDriverRefundResult($order, $result, 'cajupay');
        } catch (\Throwable $e) {
            return $this->mapRefundException($order, 'cajupay', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{status: string, note: ?string, error_code?: string}
     */
    private function tryVersellRefund(object $driver, Order $order, array $credentials): array
    {
        if (! $driver instanceof VersellDriver) {
            return ['status' => 'skipped', 'note' => 'Driver Versell indisponível para reembolso.'];
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $endToEndId = $driver->resolveEndToEndId(
            $credentials,
            is_string($order->gateway_id) ? $order->gateway_id : null,
            $meta
        );

        if ($endToEndId === null || $endToEndId === '') {
            return [
                'status' => 'failed',
                'note' => 'Versell: endToEndId do Pix não encontrado no pedido. Aguarde o webhook de pagamento ou consulte a cobrança.',
                'error_code' => 'missing_end_to_end_id',
            ];
        }

        // Persiste e2eid resolvido via API (caso só viesse do GET /cob)
        if (trim((string) ($meta['versell_end_to_end_id'] ?? '')) === '') {
            $meta['versell_end_to_end_id'] = $endToEndId;
            $order->update(['metadata' => $meta]);
        }

        try {
            $result = $driver->refundTransaction(
                $credentials,
                $endToEndId,
                (float) $order->amount,
                (string) $order->id
            );

            return $this->mapDriverRefundResult($order, $result, 'versell');
        } catch (\Throwable $e) {
            return $this->mapRefundException($order, 'versell', $e);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{status: string, note: ?string, error_code?: string}
     */
    private function tryCieloRefund(object $driver, Order $order, array $credentials): array
    {
        if (! $driver instanceof CieloDriver) {
            return ['status' => 'skipped', 'note' => 'Driver Cielo indisponível para reembolso.'];
        }

        $paymentId = is_string($order->gateway_id) ? trim($order->gateway_id) : '';
        if ($paymentId === '') {
            return ['status' => 'skipped', 'note' => 'Sem PaymentId Cielo no pedido.'];
        }

        try {
            $result = $driver->refundTransaction(
                $credentials,
                $paymentId,
                (float) $order->amount,
                (string) $order->id
            );

            return $this->mapDriverRefundResult($order, $result, 'cielo');
        } catch (\Throwable $e) {
            return $this->mapRefundException($order, 'cielo', $e);
        }
    }

    /**
     * @param  array{success?: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>, refund_id?: string}  $result
     * @return array{status: string, note: ?string, error_code?: string}
     */
    private function mapDriverRefundResult(Order $order, array $result, string $gatewaySlug): array
    {
        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $errorCode = is_string($result['error_code'] ?? null) ? $result['error_code'] : null;
        if ($errorCode === null && is_string($raw['error'] ?? null)) {
            $errorCode = strtolower(trim($raw['error']));
        }

        if (($result['success'] ?? false) === true) {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            if ($gatewaySlug === 'cajupay' && ! empty($result['pending'])) {
                $meta['cajupay_pix_refund_status'] = $raw['status'] ?? 'submitted';
                $meta['cajupay_pix_refund_pending'] = true;
                $order->update(['metadata' => $meta]);

                return [
                    'status' => 'gateway_pending',
                    'note' => $result['message'] ?? 'Reembolso PIX enviado; aguardando confirmação.',
                ];
            }

            if ($gatewaySlug === 'versell') {
                if (! empty($result['refund_id'])) {
                    $meta['versell_refund_id'] = (string) $result['refund_id'];
                }
                $meta['versell_refund_status'] = $raw['status'] ?? (! empty($result['pending']) ? 'EM_PROCESSAMENTO' : 'DEVOLVIDO');
                $meta['versell_refund_pending'] = ! empty($result['pending']);
                $order->update(['metadata' => $meta]);

                if (! empty($result['pending'])) {
                    return [
                        'status' => 'gateway_pending',
                        'note' => $result['message'] ?? 'Reembolso PIX enviado; aguardando liquidação na Versell.',
                    ];
                }
            }

            if ($gatewaySlug === 'cielo') {
                $meta['cielo_refund_status'] = $raw['Status'] ?? ($result['pending'] ? 'scheduled' : 'requested');
                $meta['cielo_refund_pending'] = ! empty($result['pending']);
                $order->update(['metadata' => $meta]);

                if (! empty($result['pending'])) {
                    return [
                        'status' => 'gateway_pending',
                        'note' => $result['message'] ?? 'Estorno enviado à Cielo; aguardando confirmação.',
                    ];
                }
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

        return [
            'status' => 'failed',
            'note' => $this->failedAcquirerNote($result['message'] ?? null, $errorCode),
            'error_code' => $errorCode,
        ];
    }

    /**
     * @return array{status: string, note: ?string, error_code?: string}
     */
    private function mapRefundException(Order $order, string $gatewaySlug, \Throwable $e): array
    {
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
