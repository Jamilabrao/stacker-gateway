<?php

namespace App\Services\Meta;

class MetaUserDataHasher
{
    public function hashEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    public function hashPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $digits !== '' ? hash('sha256', $digits) : null;
    }

    public function hashName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';

        return $normalized !== '' ? hash('sha256', $normalized) : null;
    }

    public function hashCity(?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($city))) ?? '';

        return $normalized !== '' ? hash('sha256', $normalized) : null;
    }

    public function hashState(?string $state): ?string
    {
        if ($state === null || trim($state) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($state)));
    }

    public function hashCountry(?string $country): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }

        $code = strtolower(trim($country));
        if (strlen($code) > 2) {
            $code = substr($code, 0, 2);
        }

        return hash('sha256', $code);
    }

    public function hashZip(?string $zip): ?string
    {
        if ($zip === null || trim($zip) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $zip) ?? '';

        return $digits !== '' ? hash('sha256', $digits) : null;
    }

    public function hashExternalId(?string $externalId): ?string
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        return hash('sha256', strtolower(trim($externalId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserData(MetaEventContext $context): array
    {
        $data = array_filter([
            'em' => ($h = $this->hashEmail($context->email)) ? [$h] : null,
            'ph' => ($h = $this->hashPhone($context->phone)) ? [$h] : null,
            'fn' => ($h = $this->hashName($context->firstName)) ? [$h] : null,
            'ln' => ($h = $this->hashName($context->lastName)) ? [$h] : null,
            'ct' => ($h = $this->hashCity($context->city)) ? [$h] : null,
            'st' => ($h = $this->hashState($context->state)) ? [$h] : null,
            'country' => ($h = $this->hashCountry($context->country)) ? [$h] : null,
            'zp' => ($h = $this->hashZip($context->zip)) ? [$h] : null,
            'external_id' => ($h = $this->hashExternalId($context->externalId)) ? [$h] : null,
            'client_ip_address' => $context->clientIp ?: null,
            'client_user_agent' => $context->clientUserAgent ?: null,
            'fbp' => $context->fbp ?: null,
            'fbc' => $context->fbc ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
