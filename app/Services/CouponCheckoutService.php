<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponCheckoutService
{
    public function findForProduct(Product $product, string $code): ?Coupon
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return Coupon::forTenant($product->tenant_id)
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->get()
            ->first(fn (Coupon $coupon) => $coupon->appliesToProduct($product));
    }

    /**
     * @return array{discount_amount: float, final_price: float, coupon: Coupon}
     */
    public function applyOrFail(Product $product, string $code, float $priceBrl): array
    {
        $coupon = $this->findForProduct($product, $code);
        if ($coupon === null) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Cupom inválido ou não disponível para este produto.'],
            ]);
        }

        $result = $coupon->applyTo($product, $priceBrl);
        if ($result === null) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Este cupom não pode ser aplicado (expirado, limite de usos atingido ou valor mínimo não atingido).'],
            ]);
        }

        return [
            'discount_amount' => $result['discount_amount'],
            'final_price' => $result['final_price'],
            'coupon' => $coupon,
        ];
    }

    /**
     * Aplica cupom sem lançar exceção (cotação de frete, estimativas).
     */
    public function tryApply(Product $product, ?string $couponCode, float $priceBrl): float
    {
        $couponCode = $couponCode !== null ? trim($couponCode) : null;
        if ($couponCode === null || $couponCode === '') {
            return $priceBrl;
        }

        $coupon = $this->findForProduct($product, $couponCode);
        if ($coupon === null) {
            return $priceBrl;
        }

        $result = $coupon->applyTo($product, $priceBrl);

        return $result !== null ? $result['final_price'] : $priceBrl;
    }

    /**
     * @return array{amount: float, discount_amount: float, coupon_code: ?string}
     */
    public function applyOptional(Product $product, ?string $couponCode, float $priceBrl): array
    {
        $couponCode = $couponCode !== null ? trim($couponCode) : null;
        if ($couponCode === null || $couponCode === '') {
            return [
                'amount' => $priceBrl,
                'discount_amount' => 0.0,
                'coupon_code' => null,
            ];
        }

        $applied = $this->applyOrFail($product, $couponCode, $priceBrl);

        return [
            'amount' => $applied['final_price'],
            'discount_amount' => $applied['discount_amount'],
            'coupon_code' => $couponCode,
        ];
    }

    /**
     * Incrementa used_count uma vez por pedido concluído (idempotente via metadata).
     */
    public function recordUsageFromCompletedOrder(Order $order): void
    {
        $code = trim((string) ($order->coupon_code ?? ''));
        if ($code === '' || $order->status !== 'completed') {
            return;
        }

        $metadata = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($metadata['coupon_usage_counted'])) {
            return;
        }

        $coupon = Coupon::forTenant($order->tenant_id)
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->first();

        if ($coupon === null) {
            return;
        }

        $incremented = DB::transaction(function () use ($coupon): bool {
            return (bool) Coupon::query()
                ->where('id', $coupon->id)
                ->where(function ($q) {
                    $q->whereNull('max_uses')
                        ->orWhereColumn('used_count', '<', 'max_uses');
                })
                ->lockForUpdate()
                ->increment('used_count');
        });

        if ($incremented) {
            $metadata['coupon_usage_counted'] = true;
            $order->forceFill(['metadata' => $metadata])->saveQuietly();
        }
    }
}
