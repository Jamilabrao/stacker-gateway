<?php

namespace App\Support;

final class ApiScopes
{
    public const WILDCARD = '*';

    public const PAYMENTS_READ = 'payments:read';

    public const PAYMENTS_WRITE = 'payments:write';

    public const PAYMENTS_REFUND = 'payments:refund';

    public const WITHDRAWALS_READ = 'withdrawals:read';

    public const WITHDRAWALS_WRITE = 'withdrawals:write';

    public const WEBHOOKS_READ = 'webhooks:read';

    public const WEBHOOKS_WRITE = 'webhooks:write';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            self::PAYMENTS_READ => [
                'label' => 'Consultar pagamentos',
                'description' => 'Listar pedidos e consultar status de pagamentos (GET).',
            ],
            self::PAYMENTS_WRITE => [
                'label' => 'Criar pagamentos',
                'description' => 'Gerar cobranças PIX, cartão e boleto (POST).',
            ],
            self::PAYMENTS_REFUND => [
                'label' => 'Estornar pagamentos',
                'description' => 'Cancelar e estornar cobranças PIX.',
            ],
            self::WITHDRAWALS_READ => [
                'label' => 'Consultar saques',
                'description' => 'Ver saldo disponível e histórico de saques.',
            ],
            self::WITHDRAWALS_WRITE => [
                'label' => 'Solicitar saques',
                'description' => 'Solicitar saques e cadastrar chave PIX de destino.',
            ],
            self::WEBHOOKS_READ => [
                'label' => 'Consultar webhook',
                'description' => 'Ver URL e eventos do webhook da integração (GET).',
            ],
            self::WEBHOOKS_WRITE => [
                'label' => 'Configurar webhook',
                'description' => 'Provisionar URL, secret e eventos do webhook (PUT).',
            ],
        ];
    }

    /**
     * @return list<array{id: string, label: string, description: string}>
     */
    public static function catalogList(): array
    {
        $list = [];
        foreach (self::catalog() as $id => $meta) {
            $list[] = [
                'id' => $id,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ];
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    public static function legacyDefaults(): array
    {
        return [self::WILDCARD];
    }

    /**
     * @param  list<string>|null  $scopes
     */
    public static function hasScope(?array $scopes, string $required, bool $isLegacy = false): bool
    {
        if ($isLegacy) {
            return true;
        }

        if ($scopes === null || $scopes === []) {
            return false;
        }

        if (in_array(self::WILDCARD, $scopes, true)) {
            return true;
        }

        return in_array($required, $scopes, true);
    }
}
