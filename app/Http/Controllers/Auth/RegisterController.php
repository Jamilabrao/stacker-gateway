<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function showRegistrationForm(): Response
    {
        if ($this->publicRegistrationBlocked()) {
            abort(403, 'Cadastro público desabilitado. Use o painel da plataforma ou crie o administrador via CLI.');
        }

        return Inertia::render('Auth/Register');
    }

    public function register(Request $request)
    {
        if ($this->publicRegistrationBlocked()) {
            abort(403, 'Cadastro público desabilitado.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $role = User::ROLE_CLIENTE;
        if (User::count() === 0 && filter_var(env('CREATE_FIRST_ADMIN', false), FILTER_VALIDATE_BOOLEAN)) {
            $role = User::ROLE_ADMIN;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'tenant_id' => null,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        if ($user->canAccessPanel()) {
            return redirect()->intended('/dashboard');
        }

        return redirect()->intended('/painel-cliente');
    }

    private function publicRegistrationBlocked(): bool
    {
        if (filter_var(env('CREATE_FIRST_ADMIN', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return User::where('role', User::ROLE_ADMIN)->exists();
    }
}
