<?php

namespace App\Services\Checkout;

use App\Support\CheckoutTurnstileSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TurnstileVerifier
{
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = CheckoutTurnstileSettings::secretKey();
        if ($secret === '') {
            return false;
        }
        if (trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));

            if (! $response->successful()) {
                Log::warning('TurnstileVerifier: HTTP error', ['status' => $response->status()]);

                return false;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return false;
            }

            return ($data['success'] ?? false) === true;
        } catch (\Throwable $e) {
            Log::warning('TurnstileVerifier: exception', ['message' => $e->getMessage()]);

            return false;
        }
    }
}
