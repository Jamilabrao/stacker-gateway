<?php

namespace App\Services\Stacker;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LicenseService
{
    private const CACHE_PATH = 'stacker/license.json';

    public function isDisabled(): bool
    {
        return (bool) config('getfy.stacker.license_disabled', false);
    }

    public function readCache(): ?array
    {
        $path = storage_path(self::CACHE_PATH);
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function verifyLicenseSignature(?array $cache): bool
    {
        if (! is_array($cache) || empty($cache['signature'])) {
            return false;
        }

        $key = (string) config('getfy.stacker.signing_key', '');
        if ($key === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $this->canonicalLicenseJson($cache), $key);

        return hash_equals($expected, (string) $cache['signature']);
    }

    private function canonicalLicenseJson(array $data): string
    {
        $payload = [
            'valid' => (bool) ($data['valid'] ?? false),
            'blocked' => (bool) ($data['blocked'] ?? false),
            'bound' => (bool) ($data['bound'] ?? false),
            'domain' => $data['domain'] ?? null,
            'expiresAt' => $data['expiresAt'] ?? null,
            'supportWhatsapp' => $data['supportWhatsapp'] ?? null,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function isBlocked(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        $cache = $this->readCache();
        if (! $cache) {
            return false;
        }

        if (! $this->verifyLicenseSignature($cache)) {
            return ! empty($cache['blocked']);
        }

        return ! empty($cache['blocked']);
    }

    public function isLicenseValid(): bool
    {
        if ($this->isDisabled()) {
            return true;
        }

        $cache = $this->readCache();
        if (! $cache) {
            return $this->legacyGraceActive();
        }

        $signed = $this->verifyLicenseSignature($cache);
        if (! $signed) {
            Log::warning('stacker.license.signature_invalid', [
                'has_signing_key' => (string) config('getfy.stacker.signing_key', '') !== '',
                'blocked' => ! empty($cache['blocked']),
                'valid' => ! empty($cache['valid']),
            ]);

            if (! empty($cache['blocked'])) {
                return false;
            }

            if (! empty($cache['valid'])) {
                return true;
            }

            return $this->withinLicenseGrace($cache) || $this->legacyGraceActive();
        }

        if (! empty($cache['blocked'])) {
            return false;
        }

        if (! empty($cache['valid'])) {
            return true;
        }

        // Assinatura OK, não bloqueado, mas valid=false (ex.: domínio): usa janela expiresAt da API.
        if ($this->withinLicenseGrace($cache)) {
            return true;
        }

        return false;
    }

    private function withinLicenseGrace(?array $cache): bool
    {
        if (! is_array($cache)) {
            return false;
        }

        $expiresAt = $cache['expiresAt'] ?? null;
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        try {
            return new \DateTimeImmutable($expiresAt) > new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }

    private function legacyGraceActive(): bool
    {
        $token = (string) config('getfy.stacker.agent_token', '');
        if ($token === '') {
            return true;
        }

        if ($this->readCache() !== null) {
            return false;
        }

        $installedAt = File::lastModified(base_path('VERSION')) ?: File::lastModified(base_path('.env'));
        if (! $installedAt) {
            return true;
        }

        return (time() - $installedAt) < (72 * 3600);
    }

    public function supportWhatsappUrl(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) config('getfy.stacker.support_whatsapp', ''));
        if ($number === '') {
            return null;
        }

        return 'https://wa.me/'.$number;
    }
}
