<?php

namespace App\Services\Stacker;

use Illuminate\Support\Facades\File;

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

    public function isLicenseValid(): bool
    {
        if ($this->isDisabled()) {
            return true;
        }

        $cache = $this->readCache();
        if (! $cache) {
            return $this->legacyGraceActive();
        }

        if (! empty($cache['blocked'])) {
            return false;
        }

        if (! empty($cache['valid'])) {
            return true;
        }

        $expiresAt = isset($cache['expiresAt']) ? strtotime((string) $cache['expiresAt']) : false;
        if ($expiresAt !== false && $expiresAt > time()) {
            return true;
        }

        return $this->legacyGraceActive();
    }

    private function legacyGraceActive(): bool
    {
        $token = (string) config('getfy.stacker.agent_token', '');
        if ($token === '') {
            return true;
        }

        $path = storage_path(self::CACHE_PATH);
        if (is_file($path)) {
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
