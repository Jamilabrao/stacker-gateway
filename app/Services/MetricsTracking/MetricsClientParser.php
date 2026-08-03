<?php

namespace App\Services\MetricsTracking;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetricsClientParser
{
    /**
     * @return array{device_type: string, os_name: string, browser_name: string}
     */
    public static function fromUserAgent(?string $ua): array
    {
        $ua = (string) $ua;
        $lower = strtolower($ua);

        $device = 'desktop';
        if (preg_match('/ipad|tablet|kindle|playbook|silk|(android(?!.*mobile))/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone|blackberry/i', $ua)) {
            $device = 'mobile';
        }

        $os = 'Other';
        if (str_contains($lower, 'windows')) {
            $os = 'Windows';
        } elseif (str_contains($lower, 'android')) {
            $os = 'Android';
        } elseif (str_contains($lower, 'iphone') || str_contains($lower, 'ipad') || str_contains($lower, 'ios')) {
            $os = 'iOS';
        } elseif (str_contains($lower, 'mac os') || str_contains($lower, 'macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($lower, 'linux')) {
            $os = 'Linux';
        }

        $browser = 'Other';
        if (str_contains($lower, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($lower, 'chrome/') && ! str_contains($lower, 'edg/')) {
            $browser = 'Chrome';
        } elseif (str_contains($lower, 'safari/') && ! str_contains($lower, 'chrome/')) {
            $browser = 'Safari';
        } elseif (str_contains($lower, 'firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($lower, 'opera') || str_contains($lower, 'opr/')) {
            $browser = 'Opera';
        }

        return [
            'device_type' => $device,
            'os_name' => $os,
            'browser_name' => $browser,
        ];
    }

    public static function hashIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        $pepper = (string) config('app.key', 'getfy');

        return hash('sha256', $pepper.'|'.$ip);
    }

    public static function maskIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $keep = array_slice($parts, 0, 4);

            return implode(':', $keep).'::';
        }

        return 'masked';
    }

    /**
     * @return array<string, mixed>
     */
    public static function trackingFromRequest(Request $request, ?array $overrides = null): array
    {
        $q = array_merge($request->query(), is_array($overrides) ? $overrides : []);

        $keys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'gclid', 'ttclid', 'src', 'sck', 'subid', 'subid2', 'subid3',
            'ref', 'campaign', 'campaign_code',
        ];

        $out = [];
        foreach ($keys as $key) {
            $val = $q[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                $out[$key] = Str::limit(trim($val), $key === 'fbclid' || $key === 'gclid' || $key === 'ttclid' ? 512 : 255, '');
            }
        }

        return $out;
    }
}
