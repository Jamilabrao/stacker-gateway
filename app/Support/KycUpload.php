<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class KycUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_FILE_KB = 20480;

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
        'application/pdf',
        'application/x-pdf',
    ];

    /** @var array<string, string> */
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
        'application/x-pdf' => 'pdf',
    ];

    /** @var list<string> */
    private const RASTER_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public static function assertValid(UploadedFile $file, string $fieldLabel): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $fieldLabel => self::messageForUploadError($file->getError()),
            ]);
        }

        $mime = self::normalizeMime($file);
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                $fieldLabel => 'Formato não permitido. Use JPG, PNG, WebP, GIF, HEIC/HEIF ou PDF.',
            ]);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                $fieldLabel => 'O arquivo não pode ser maior que 20 MB.',
            ]);
        }

        self::assertContentMatchesMime($file, $mime, $fieldLabel);
    }

    public static function extensionForMime(string $mime): string
    {
        return self::MIME_TO_EXTENSION[$mime] ?? 'bin';
    }

    public static function messageForUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo grande demais para o servidor. Envie um arquivo de até 20 MB ou reduza a qualidade da foto.',
            UPLOAD_ERR_PARTIAL => 'Upload interrompido. Tente novamente com conexão estável.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi recebido. Selecione o documento novamente.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Falha temporária no servidor ao receber o arquivo. Tente novamente.',
            default => 'Não foi possível receber o arquivo. Tente outro formato ou um arquivo menor (máx. 20 MB).',
        };
    }

    public static function normalizeMime(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        if (! is_string($mime) || $mime === '' || $mime === 'application/octet-stream') {
            return '';
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        return $mime;
    }

    public static function detectPostTooLarge(): ?string
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return null;
        }

        $postMax = self::iniBytesToInt(ini_get('post_max_size'));
        if ($postMax > 0 && $contentLength > $postMax) {
            return 'A requisição excedeu o limite do servidor (post_max_size). Envie um arquivo por vez (até 20 MB).';
        }

        return null;
    }

    private static function assertContentMatchesMime(UploadedFile $file, string $mime, string $fieldLabel): void
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            throw ValidationException::withMessages([
                $fieldLabel => 'Não foi possível ler o arquivo enviado.',
            ]);
        }

        if (in_array($mime, self::RASTER_IMAGE_MIMES, true)) {
            $size = @getimagesize($path);
            if (! is_array($size) || ! isset($size[0], $size[1])) {
                throw ValidationException::withMessages([
                    $fieldLabel => 'O arquivo não é uma imagem válida. Use JPG, PNG, WebP ou GIF.',
                ]);
            }

            return;
        }

        if (in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            $header = @file_get_contents($path, false, null, 0, 5);
            if (! is_string($header) || ! str_starts_with($header, '%PDF-')) {
                throw ValidationException::withMessages([
                    $fieldLabel => 'O arquivo não é um PDF válido.',
                ]);
            }

            return;
        }

        // HEIC/HEIF: MIME finfo only (no GD decode — preserves iPhone uploads).
    }

    private static function iniBytesToInt(string|false $value): int
    {
        if ($value === false || $value === '') {
            return 0;
        }

        $value = trim((string) $value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
