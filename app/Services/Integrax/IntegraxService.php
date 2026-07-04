<?php

namespace App\Services\Integrax;

use App\Models\PlatformIntegraxSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntegraxService
{
    public function settings(): PlatformIntegraxSetting
    {
        return PlatformIntegraxSetting::instance();
    }

    public function isEnabled(): bool
    {
        return $this->settings()->isConfigured();
    }

    public function normalizePhone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            return $digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '55'.$digits;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function renderMessage(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }

        return str_replace(array_keys($replace), array_values($replace), $template);
    }

    public function assertMessageLength(string $message): void
    {
        $max = (int) config('integrax.max_message_length', 160);
        if (mb_strlen($message) > $max) {
            throw new \InvalidArgumentException(
                'Mensagem SMS excede '.$max.' caracteres ('.mb_strlen($message).' após substituição).'
            );
        }
    }

    public function sendSms(string $phone, string $message, ?PlatformIntegraxSetting $settings = null): void
    {
        $settings ??= $this->settings();

        if (! $settings->isConfigured()) {
            throw new \RuntimeException('IntegraX não está configurada ou ativa.');
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Telefone inválido para envio SMS.');
        }

        $this->assertMessageLength($message);

        $token = $settings->api_token;
        $url = rtrim((string) config('integrax.api_base_url'), '/').'/'.urlencode((string) $token).'/send-sms';

        $response = Http::timeout((int) config('integrax.http_timeout', 15))
            ->acceptJson()
            ->post($url, [
                'to' => [$normalized],
                'from' => (string) $settings->sender_from,
                'message' => $message,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'IntegraX API error: '.$response->status().' '.$response->body()
            );
        }

        if (config('integrax.debug', false)) {
            Log::debug('IntegraX SMS sent', [
                'phone' => $normalized,
                'message_length' => mb_strlen($message),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function buildMessageFromTemplate(string $template, array $vars): string
    {
        $template = trim($template);
        if ($template === '') {
            throw new \InvalidArgumentException('Template SMS vazio.');
        }

        $message = $this->renderMessage($template, $vars);
        $this->assertMessageLength($message);

        return $message;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function buildMessageForEvent(string $eventType, array $vars, ?PlatformIntegraxSetting $settings = null): string
    {
        $settings ??= $this->settings();
        $template = trim((string) ($settings->messageTemplateFor($eventType) ?? ''));

        if ($template === '') {
            $template = trim((string) (config('integrax.defaults.messages.'.$eventType) ?? ''));
        }

        if ($template === '') {
            throw new \InvalidArgumentException('Template SMS não configurado para '.$eventType);
        }

        return $this->buildMessageFromTemplate($template, $vars);
    }
}
