<?php

namespace App\Services\Shipping;

use App\Models\ShippingRule;

class ShippingRuleMatcher
{
    /**
     * @param  array{uf: string, city: string}|null  $destination
     */
    public function matches(ShippingRule $rule, string $cepDigits, ?array $destination): bool
    {
        if (! $rule->is_active) {
            return false;
        }

        $config = is_array($rule->match_config) ? $rule->match_config : [];

        return match ($rule->match_type) {
            ShippingRule::MATCH_ALL => true,
            ShippingRule::MATCH_STATE => $this->matchesState($config, $destination),
            ShippingRule::MATCH_CITY => $this->matchesCity($config, $destination),
            ShippingRule::MATCH_CEP_RANGE => $this->matchesCepRange($config, $cepDigits),
            ShippingRule::MATCH_CEP_PREFIX => $this->matchesCepPrefix($config, $cepDigits),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{uf: string, city: string}|null  $destination
     */
    private function matchesState(array $config, ?array $destination): bool
    {
        if ($destination === null || ($destination['uf'] ?? '') === '') {
            return false;
        }
        $states = $config['states'] ?? [];
        if (! is_array($states)) {
            return false;
        }
        $uf = strtoupper($destination['uf']);
        foreach ($states as $state) {
            if (strtoupper((string) $state) === $uf) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{uf: string, city: string}|null  $destination
     */
    private function matchesCity(array $config, ?array $destination): bool
    {
        if ($destination === null) {
            return false;
        }
        $items = $config['items'] ?? [];
        if (! is_array($items)) {
            return false;
        }
        $destUf = strtoupper($destination['uf'] ?? '');
        $destCity = $this->normalizeCityName($destination['city'] ?? '');
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $uf = strtoupper((string) ($item['uf'] ?? ''));
            $city = $this->normalizeCityName((string) ($item['city'] ?? ''));
            if ($uf === $destUf && $city !== '' && $city === $destCity) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCepRange(array $config, string $cepDigits): bool
    {
        $from = preg_replace('/\D/', '', (string) ($config['from'] ?? '')) ?? '';
        $to = preg_replace('/\D/', '', (string) ($config['to'] ?? '')) ?? '';
        if (strlen($from) !== 8 || strlen($to) !== 8) {
            return false;
        }

        return $cepDigits >= $from && $cepDigits <= $to;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesCepPrefix(array $config, string $cepDigits): bool
    {
        $prefixes = $config['prefixes'] ?? [];
        if (! is_array($prefixes)) {
            return false;
        }
        foreach ($prefixes as $prefix) {
            $p = preg_replace('/\D/', '', (string) $prefix) ?? '';
            if ($p !== '' && str_starts_with($cepDigits, $p)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCityName(string $city): string
    {
        return mb_strtolower(trim($city));
    }
}
