<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateDevAdminCommand extends Command
{
    protected $signature = 'getfy:create-dev-admin
                            {--email=admin@dev.com : E-mail (login em /plataforma/login)}
                            {--password= : Senha (omissão: igual ao e-mail)}
                            {--name=Admin Dev : Nome exibido}';

    protected $description = 'Cria ou atualiza um utilizador platform_admin (painel /plataforma).';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) ($this->option('password') ?: $email);
        $name = trim((string) $this->option('name')) ?: 'Admin Dev';

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('E-mail inválido.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        $payload = [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ];

        if ($user) {
            $user->update($payload);
            $this->info("Utilizador atualizado: {$email} (platform_admin). Login: /plataforma/login");
        } else {
            User::create($payload);
            $this->info("Utilizador criado: {$email} (platform_admin). Login: /plataforma/login");
        }

        return self::SUCCESS;
    }
}
