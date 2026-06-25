<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DockerEnvBootstrap;
use App\Support\DockerSetupState;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class CreateFirstAdminController extends Controller
{
    /**
     * Show the form to create the first admin user. Only when User::count() === 0.
     */
    public function show(): Response|RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        DockerEnvBootstrap::ensureAppKey();
        DockerEnvBootstrap::ensureUsersSchemaReady();

        if (User::count() > 0) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/CreateFirstAdmin');
    }

    /**
     * Create the first admin user. Only when User::count() === 0. Reject with 403 otherwise.
     */
    public function store(Request $request): RedirectResponse
    {
        if (DockerSetupState::isDocker() && ! DockerSetupState::isSetupDone()) {
            return redirect('/docker-setup');
        }

        DockerEnvBootstrap::ensureAppKey();
        DockerEnvBootstrap::ensureUsersSchemaReady();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        try {
            $user = DB::transaction(function () use ($validated) {
                if (User::query()->lockForUpdate()->count() > 0) {
                    abort(403, 'O primeiro administrador já foi criado.');
                }

                return User::create([
                    'name' => HtmlSanitizer::plainText($validated['name'], 255),
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'role' => User::ROLE_PLATFORM_ADMIN,
                    'tenant_id' => null,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);
            $friendly = DockerEnvBootstrap::friendlyDatabaseError($e);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => $friendly ?? 'Não foi possível criar o administrador. Tente novamente em instantes.',
            ]);
        }

        Auth::login($user);

        return redirect()->intended(route('plataforma.dashboard'));
    }
}
