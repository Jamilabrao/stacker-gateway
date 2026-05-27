<?php

namespace App\Support;

final class AccessEmailSendResult
{
    public const REASON_SUCCESS = 'success';

    public const REASON_NO_PRODUCT = 'no_product';

    public const REASON_LINK_PAGAMENTO = 'link_pagamento';

    public const REASON_INVALID_EMAIL = 'invalid_email';

    public const REASON_SMTP_NOT_CONFIGURED = 'smtp_not_configured';

    public const REASON_SMTP_SEND_FAILED = 'smtp_send_failed';

    public const REASON_ALREADY_SENT = 'already_sent';

    public const REASON_EMPTY_TEMPLATE = 'empty_template';

    public function __construct(
        public readonly bool $success,
        public readonly string $reason,
        public readonly string $message = '',
    ) {}

    public static function ok(): self
    {
        return new self(true, self::REASON_SUCCESS, 'E-mail enviado com sucesso.');
    }

    public static function fail(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }

    public static function messageForReason(string $reason, ?string $detail = null): string
    {
        $messages = [
            self::REASON_NO_PRODUCT => 'Pedido sem produto associado.',
            self::REASON_LINK_PAGAMENTO => 'Este tipo de produto não envia e-mail de acesso.',
            self::REASON_INVALID_EMAIL => 'O pedido não possui um e-mail válido do comprador.',
            self::REASON_SMTP_NOT_CONFIGURED => 'Nenhum servidor de e-mail configurado. Configure o SMTP em Plataforma → Configurações → E-mail.',
            self::REASON_SMTP_SEND_FAILED => 'Falha ao enviar o e-mail pelo servidor SMTP.',
            self::REASON_EMPTY_TEMPLATE => 'O produto não possui conteúdo no template de e-mail de acesso.',
        ];

        $base = $messages[$reason] ?? 'Não foi possível enviar o e-mail de acesso.';

        if ($detail !== null && $detail !== '' && $reason === self::REASON_SMTP_SEND_FAILED) {
            return $base.' Detalhe: '.$detail;
        }

        return $base;
    }
}
