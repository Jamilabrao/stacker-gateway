<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ViaCepResolver
{
    /**
     * @return array{uf: string, city: string, neighborhood: string, street: string}|null
     */
    public function resolve(string $cep): ?array
    {
        $digits = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($digits) !== 8) {
            return null;
        }

        return Cache::remember('viacep:'.$digits, now()->addDay(), function () use ($digits) {
            try {
                $response = Http::timeout(8)->get("https://viacep.com.br/ws/{$digits}/json/");
                if (! $response->successful()) {
                    return null;
                }
                $data = $response->json();
                if (! is_array($data) || ! empty($data['erro'])) {
                    return null;
                }

                return [
                    'uf' => strtoupper((string) ($data['uf'] ?? '')),
                    'city' => (string) ($data['localidade'] ?? ''),
                    'neighborhood' => (string) ($data['bairro'] ?? ''),
                    'street' => (string) ($data['logradouro'] ?? ''),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
