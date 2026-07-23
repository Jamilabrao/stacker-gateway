<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

trait ClearsAuthSessionCookies
{
    /**
     * Expira cookie de sessão e remember_* com os atributos atuais do app
     * (evita cookie órfão após Domain/Secure/nome antigos).
     */
    protected function clearAuthSessionCookies(): void
    {
        Cookie::queue(Cookie::forget((string) config('session.cookie')));
        Cookie::queue(Cookie::forget(Auth::guard()->getRecallerName()));
    }
}
