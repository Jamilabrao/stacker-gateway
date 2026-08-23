<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Resolve ou cria conta de cliente para matrícula manual (Alunos / import / Member Builder).
 * Contas existentes: reutiliza sem alterar senha, nome ou role.
 */
class MemberStudentAccountService
{
    /**
     * @return array{user: User, created: bool}
     *
     * @throws ValidationException
     */
    public function resolveOrCreateCliente(string $email, string $name, ?string $password = null): array
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user) {
            if (! $user->isCliente()) {
                throw ValidationException::withMessages([
                    'email' => 'Este e-mail já pertence a outra conta da plataforma e não pode ser cadastrado como aluno.',
                ]);
            }

            return ['user' => $user, 'created' => false];
        }

        $password = is_string($password) ? trim($password) : '';
        if (strlen($password) < 6) {
            throw ValidationException::withMessages([
                'password' => 'Informe uma senha para cadastrar um novo aluno.',
            ]);
        }

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Informe o nome para cadastrar um novo aluno.',
            ]);
        }

        $user = User::create([
            'name' => mb_substr($name, 0, 255),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        return ['user' => $user, 'created' => true];
    }
}
