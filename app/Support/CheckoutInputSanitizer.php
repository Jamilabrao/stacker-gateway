<?php

namespace App\Support;

final class CheckoutInputSanitizer
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function sanitize(array $validated): array
    {
        $plainFields = [
            'name' => 255,
            'card_holder_name' => 255,
            'address_street' => 255,
            'address_number' => 32,
            'address_neighborhood' => 120,
            'address_city' => 120,
            'shipping_street' => 255,
            'shipping_number' => 32,
            'shipping_complement' => 120,
            'shipping_neighborhood' => 120,
            'shipping_city' => 120,
        ];

        foreach ($plainFields as $key => $max) {
            if (array_key_exists($key, $validated) && is_string($validated[$key])) {
                $validated[$key] = HtmlSanitizer::plainText($validated[$key], $max) ?: null;
            }
        }

        if (isset($validated['address_state']) && is_string($validated['address_state'])) {
            $validated['address_state'] = strtoupper(substr(HtmlSanitizer::plainText($validated['address_state'], 2), 0, 2));
        }

        if (isset($validated['shipping_state']) && is_string($validated['shipping_state'])) {
            $validated['shipping_state'] = strtoupper(substr(HtmlSanitizer::plainText($validated['shipping_state'], 2), 0, 2));
        }

        return $validated;
    }
}
