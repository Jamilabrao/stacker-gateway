<?php

namespace App\Support;

use App\Gateways\GatewayRegistry;
use App\Plugins\PluginRegistry;

/**
 * Adquirentes que exigem plugin instalado e ativo (ex.: Lina como módulo vendável).
 * Continuam listados no Financeiro, mas configuração e pagamento só liberam com o plugin.
 */
class GatewayPluginRequirement
{
    public static function requiredPluginSlug(?array $gatewayDef): ?string
    {
        if (! is_array($gatewayDef)) {
            return null;
        }
        $slug = $gatewayDef['requires_plugin'] ?? null;
        if (! is_string($slug)) {
            return null;
        }
        $slug = trim($slug);

        return $slug !== '' ? $slug : null;
    }

    public static function isUnlocked(string $gatewaySlug): bool
    {
        $def = GatewayRegistry::get($gatewaySlug);
        $plugin = self::requiredPluginSlug(is_array($def) ? $def : null);
        if ($plugin === null) {
            return true;
        }

        return PluginRegistry::isActive($plugin);
    }

    public static function lockedUserMessage(string $gatewaySlug): string
    {
        $def = GatewayRegistry::get($gatewaySlug);
        $name = is_array($def) ? (string) ($def['name'] ?? $gatewaySlug) : $gatewaySlug;
        $pluginSlug = self::requiredPluginSlug(is_array($def) ? $def : null) ?? 'necessário';
        $pluginName = self::pluginDisplayName($pluginSlug);

        return "O adquirente {$name} exige o plugin «{$pluginName}» instalado e ativo. "
            .'Fale com o suporte para obter e instalar o módulo.';
    }

    public static function assertUnlocked(string $gatewaySlug): void
    {
        if (! self::isUnlocked($gatewaySlug)) {
            throw new \RuntimeException(self::lockedUserMessage($gatewaySlug));
        }
    }

    /**
     * Props para o card / modal do Financeiro.
     *
     * @param  array<string, mixed>  $gatewayDef
     * @return array{
     *     requires_plugin: string|null,
     *     plugin_locked: bool,
     *     plugin_name: string|null,
     *     plugin_locked_title: string|null,
     *     plugin_locked_message: string|null
     * }
     */
    public static function uiPropsForDefinition(array $gatewayDef): array
    {
        $pluginSlug = self::requiredPluginSlug($gatewayDef);
        if ($pluginSlug === null) {
            return [
                'requires_plugin' => null,
                'plugin_locked' => false,
                'plugin_name' => null,
                'plugin_locked_title' => null,
                'plugin_locked_message' => null,
            ];
        }

        $gatewaySlug = (string) ($gatewayDef['slug'] ?? '');
        $locked = $gatewaySlug === '' || ! self::isUnlocked($gatewaySlug);
        $pluginName = self::pluginDisplayName($pluginSlug);
        $gatewayName = (string) ($gatewayDef['name'] ?? $gatewaySlug);

        return [
            'requires_plugin' => $pluginSlug,
            'plugin_locked' => $locked,
            'plugin_name' => $pluginName,
            'plugin_locked_title' => $locked ? 'Plugin necessário' : null,
            'plugin_locked_message' => $locked
                ? "Para configurar e usar o adquirente {$gatewayName}, é necessário instalar e ativar o plugin «{$pluginName}». "
                    .'Este é um módulo adicional. Fale com o suporte para obter a licença e o instalador.'
                : null,
        ];
    }

    public static function pluginDisplayName(string $pluginSlug): string
    {
        foreach (PluginRegistry::installed() as $p) {
            if (($p['slug'] ?? '') === $pluginSlug) {
                return (string) ($p['name'] ?? $pluginSlug);
            }
        }

        return match ($pluginSlug) {
            'linaopenx' => 'Lina OpenX',
            default => $pluginSlug,
        };
    }
}
