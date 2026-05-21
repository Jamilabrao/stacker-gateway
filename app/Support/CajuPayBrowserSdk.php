<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * URL da API CajuPay usada pelo SDK no navegador (embedded checkout).
 */
final class CajuPayBrowserSdk
{
    public static function directApiBaseUrl(): string
    {
        return rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
    }

    /**
     * Em HTTPS usa a API direta; em HTTP local (ex.: Laragon) usa proxy same-origin para evitar CORS.
     */
    public static function apiBaseUrlForBrowser(?Request $request = null): string
    {
        $direct = self::directApiBaseUrl();

        if ($request === null) {
            return $direct;
        }

        if ($request->secure()) {
            return $direct;
        }

        $useProxy = filter_var(config('services.cajupay.sdk_browser_proxy', true), FILTER_VALIDATE_BOOLEAN);
        if (! $useProxy) {
            return $direct;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/').'/checkout/cajupay/sdk-api';
    }
}
