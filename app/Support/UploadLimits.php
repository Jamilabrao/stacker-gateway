<?php

namespace App\Support;

final class UploadLimits
{
    public static function memberBuilderImageMaxKb(): int
    {
        return max(1, (int) config('member_builder_uploads.image_max_kb', 10240));
    }

    public static function memberBuilderBadgeMaxKb(): int
    {
        return max(1, (int) config('member_builder_uploads.badge_image_max_kb', 5120));
    }

    public static function memberBuilderPdfMaxKb(): int
    {
        return max(1, (int) config('member_builder_uploads.pdf_max_kb', 51200));
    }

    public static function memberBuilderImageMaxMb(): int
    {
        return max(1, (int) floor(self::memberBuilderImageMaxKb() / 1024));
    }

    public static function memberBuilderBadgeMaxMb(): int
    {
        return max(1, (int) floor(self::memberBuilderBadgeMaxKb() / 1024));
    }

    public static function memberBuilderPdfMaxMb(): int
    {
        return max(1, (int) floor(self::memberBuilderPdfMaxKb() / 1024));
    }

    /**
     * @return array{image_max_mb: int, badge_max_mb: int, pdf_max_mb: int}
     */
    public static function memberBuilderForFrontend(): array
    {
        return [
            'image_max_mb' => self::memberBuilderImageMaxMb(),
            'badge_max_mb' => self::memberBuilderBadgeMaxMb(),
            'pdf_max_mb' => self::memberBuilderPdfMaxMb(),
        ];
    }

    public static function messageForPhpUploadError(int $errorCode, int $maxMb): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Arquivo grande demais para o servidor (máx. {$maxMb} MB). Reduza o tamanho ou peça ao administrador para aumentar os limites de upload.",
            UPLOAD_ERR_PARTIAL => 'Upload interrompido. Tente novamente com conexão estável.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi recebido. Selecione o arquivo novamente.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Falha temporária no servidor ao receber o arquivo. Tente novamente.',
            default => "Não foi possível receber o arquivo (máx. {$maxMb} MB).",
        };
    }

    public static function assertUploadedFileIsValid(?\Illuminate\Http\UploadedFile $file, int $maxMb, string $field = 'file'): void
    {
        if ($file === null) {
            return;
        }

        if ($file->isValid()) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            $field => self::messageForPhpUploadError($file->getError(), $maxMb),
        ]);
    }
}
