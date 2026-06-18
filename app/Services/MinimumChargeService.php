<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class MinimumChargeService
{
    public const SETTING_API_PIX = 'api_pix_minimum_charge_brl';

    public const SETTING_PLATFORM = 'platform_minimum_charge_brl';

    public const DEFAULT_API_PIX = 0.01;

    public const DEFAULT_PLATFORM = 0.0;

    public function apiPixMinimumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_API_PIX, self::DEFAULT_API_PIX);
    }

    public function platformMinimumBrl(): float
    {
        return $this->readGlobalSetting(self::SETTING_PLATFORM, self::DEFAULT_PLATFORM);
    }

    public function apiPixMinimumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_API_PIX);
        if ($override !== null) {
            return $override;
        }

        return $this->apiPixMinimumBrl();
    }

    public function platformMinimumBrlForTenant(?int $tenantId): float
    {
        $override = $this->readTenantOverride($tenantId, self::SETTING_PLATFORM);
        if ($override !== null) {
            return $override;
        }

        return $this->platformMinimumBrl();
    }

    public function tenantApiPixOverride(?int $tenantId): ?float
    {
        return $this->readTenantOverride($tenantId, self::SETTING_API_PIX);
    }

    public function tenantPlatformOverride(?int $tenantId): ?float
    {
        return $this->readTenantOverride($tenantId, self::SETTING_PLATFORM);
    }

    /**
     * @param  float|null  $apiMin  null removes tenant override (inherit global)
     * @param  float|null  $platformMin  null removes tenant override (inherit global)
     */
    public function setTenantOverrides(int $tenantId, ?float $apiMin, ?float $platformMin): void
    {
        $this->setTenantApiPixOverride($tenantId, $apiMin);
        $this->setTenantPlatformOverride($tenantId, $platformMin);
    }

    public function setTenantApiPixOverride(int $tenantId, ?float $value): void
    {
        $this->writeTenantOverride($tenantId, self::SETTING_API_PIX, $value);
    }

    public function setTenantPlatformOverride(int $tenantId, ?float $value): void
    {
        $this->writeTenantOverride($tenantId, self::SETTING_PLATFORM, $value);
    }

    public function assertApiPayment(float $amountBrl, ?int $tenantId = null): void
    {
        $min = $this->apiPixMinimumBrlForTenant($tenantId);
        if ($amountBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'amount' => $this->messageFor($min, 'api_pix'),
            ]);
        }
    }

    public function assertPlatformCheckout(float $totalBrl, ?int $tenantId = null): void
    {
        $min = $this->platformMinimumBrlForTenant($tenantId);
        if ($min <= 0) {
            return;
        }

        if ($totalBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'amount' => $this->messageFor($min, 'platform_checkout'),
            ]);
        }
    }

    public function assertPlatformProductPrice(float $priceBrl, ?int $tenantId = null): void
    {
        $min = $this->platformMinimumBrlForTenant($tenantId);
        if ($min <= 0) {
            return;
        }

        if ($priceBrl + 1e-9 < $min) {
            throw ValidationException::withMessages([
                'price' => $this->messageFor($min, 'platform_product'),
            ]);
        }
    }

    public function messageFor(float $min, string $channel): string
    {
        $formatted = $this->formatBrl($min);

        return match ($channel) {
            'api_pix' => "Valor mínimo para cobrança via API PIX é {$formatted}.",
            'platform_checkout' => "Valor mínimo para cobrança na plataforma é {$formatted}.",
            'platform_product' => "Ticket mínimo da plataforma é {$formatted}. Ajuste o preço do produto, oferta ou plano.",
            default => "Valor mínimo de cobrança é {$formatted}.",
        };
    }

    public function formatBrl(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    private function readGlobalSetting(string $key, float $default): float
    {
        $raw = Setting::get($key, (string) $default, null);

        return max(0, round((float) $raw, 2));
    }

    private function readTenantOverride(?int $tenantId, string $key): ?float
    {
        if ($tenantId === null || $tenantId <= 0) {
            return null;
        }

        $row = Setting::query()
            ->where('key', $key)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return max(0, round((float) $row->value, 2));
    }

    private function writeTenantOverride(int $tenantId, string $key, ?float $value): void
    {
        if ($value === null) {
            Setting::query()
                ->where('key', $key)
                ->where('tenant_id', $tenantId)
                ->delete();

            return;
        }

        Setting::set($key, (string) round(max(0, $value), 2), $tenantId);
    }
}
