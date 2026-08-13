<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformTotpService;
use App\Services\StorageService;
use App\Support\HtmlSanitizer;
use App\Support\MerchantProfileSnapshot;
use App\Support\RemoteStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        return Inertia::render('Profile/Index', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'avatar_url' => $user->avatar ? app(StorageService::class)->url($user->avatar) : null,
            ],
            'registration' => MerchantProfileSnapshot::forUser($user, maskDocuments: false),
            'totp_enabled' => PlatformTotpService::isEnabledFor($user),
            'push_preferences' => \App\Support\UserPushPreferences::forUserId((int) $user->id),
        ]);
    }

    public function updatePushPreferences(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'sale_approved' => ['nullable', 'boolean'],
            'pix_generated' => ['nullable', 'boolean'],
            'boleto_generated' => ['nullable', 'boolean'],
            'withdrawal_paid' => ['nullable', 'boolean'],
            'affiliate_sale_approved' => ['nullable', 'boolean'],
            'affiliate_enrollment_approved' => ['nullable', 'boolean'],
            'daily_summary' => ['nullable', 'boolean'],
            'system' => ['nullable', 'boolean'],
            'show_product_name' => ['nullable', 'boolean'],
            'show_sale_amount' => ['nullable', 'boolean'],
            'sale_amount_mode' => ['nullable', 'string', 'in:gross,net'],
            'show_payment_method' => ['nullable', 'boolean'],
        ]);

        \App\Support\UserPushPreferences::upsert((int) $user->id, $validated, $request);

        return redirect()->route('profile.index')->with('success', 'Preferências de notificações atualizadas.');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user)],
            'avatar' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ], [
            'username.unique' => 'Este nome de usuário já está em uso.',
        ]);

        $user->name = HtmlSanitizer::plainText($validated['name'], 255);
        $user->username = $validated['username'] ?: null;

        if ($request->hasFile('avatar')) {
            try {
                $storage = app(StorageService::class);
                if ($user->avatar && $storage->exists($user->avatar)) {
                    $storage->delete($user->avatar);
                }
                $user->avatar = $storage->putFile('avatars', $request->file('avatar'));
            } catch (\Throwable $e) {
                $message = $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : RemoteStorage::friendlyErrorMessage($e);

                return redirect()->back()->withErrors(['avatar' => $message])->withInput();
            }
        }

        $user->save();

        return redirect()->route('profile.index')->with('success', 'Perfil atualizado.');
    }

    public function updateUsername(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user)],
        ], [
            'username.unique' => 'Este nome de usuário já está em uso.',
        ]);

        $user->username = $validated['username'] ?: null;
        $user->save();

        return back()->with('success', 'Nome de usuário atualizado.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], [
            'current_password.required' => 'Informe a senha atual.',
            'password.required' => 'O campo nova senha é obrigatório.',
            'password.confirmed' => 'A confirmação da senha não confere.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'A senha atual está incorreta.']);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return redirect()->route('profile.index')->with('success', 'Senha alterada.');
    }
}
