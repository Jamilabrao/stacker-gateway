<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Checkout\TurnstileVerifier;
use App\Support\LoginTurnstileSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait ValidatesAuthTurnstile
{
    protected function validateLoginTurnstile(Request $request): ?RedirectResponse
    {
        if (! LoginTurnstileSettings::isRequired()) {
            return null;
        }

        $token = trim((string) $request->input('turnstile_token', ''));
        if ($token === '' || ! app(TurnstileVerifier::class)->verify($token, $request->ip())) {
            return back()
                ->withErrors(['turnstile_token' => 'Confirme que você não é um robô e tente novamente.'])
                ->withInput($request->except('password', 'turnstile_token'));
        }

        return null;
    }
}
