<?php

namespace App\Support;

final class CheckoutConfigUrlSanitizer
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function sanitize(array $config): array
    {
        if (isset($config['support_button']) && is_array($config['support_button'])) {
            $url = $config['support_button']['url'] ?? null;
            $safe = SafeUrl::normalizeHttpUrl(is_string($url) ? $url : null);
            $config['support_button']['url'] = $safe ?? '#';
        }

        if (isset($config['reviews']) && is_array($config['reviews'])) {
            foreach ($config['reviews'] as $i => $review) {
                if (! is_array($review)) {
                    continue;
                }
                foreach (['photo', 'testimonial_image', 'image', 'avatar'] as $imgKey) {
                    if (! isset($review[$imgKey]) || ! is_string($review[$imgKey])) {
                        continue;
                    }
                    $safe = SafeUrl::normalizeHttpUrl($review[$imgKey]);
                    $config['reviews'][$i][$imgKey] = $safe ?? '';
                }
                if (isset($review['author']) && is_string($review['author'])) {
                    $config['reviews'][$i]['author'] = HtmlSanitizer::plainText($review['author'], 120);
                }
                if (isset($review['description']) && is_string($review['description'])) {
                    $config['reviews'][$i]['description'] = HtmlSanitizer::plainTextMultiline($review['description'], 2000);
                }
            }
        }

        if (isset($config['footer']) && is_array($config['footer'])) {
            foreach (['privacy_url', 'terms_url', 'refund_url'] as $urlKey) {
                if (! isset($config['footer'][$urlKey]) || ! is_string($config['footer'][$urlKey])) {
                    continue;
                }
                $safe = SafeUrl::normalizeHttpUrl($config['footer'][$urlKey]);
                $config['footer'][$urlKey] = $safe ?? '';
            }
        }

        return $config;
    }
}
