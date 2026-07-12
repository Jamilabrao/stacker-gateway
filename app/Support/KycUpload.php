<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class KycUpload
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_FILE_KB = 20480;

    public const MIN_WIDTH = 200;

    public const MIN_HEIGHT = 200;

    public const MAX_WIDTH = 10000;

    public const MAX_HEIGHT = 10000;

    public const MAX_PIXELS = 20_000_000;

    /** @var list<string> */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
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
    ];

    public static function assertValid(UploadedFile $file, string $fieldLabel): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                $fieldLabel => self::messageForUploadError($file->getError()),
            ]);
        }

        $path = $file->getRealPath();
        if (is_string($path) && $path !== '' && is_readable($path)) {
            self::assertNotGif($path, $fieldLabel);
        }

        $mime = self::normalizeMime($file);
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                $fieldLabel => 'Formato não permitido. Use JPG, PNG, WebP, HEIC/HEIF ou PDF.',
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

        self::assertNotGif($path, $fieldLabel);

        if (in_array($mime, self::RASTER_IMAGE_MIMES, true)) {
            self::assertRasterImageValid($path, $mime, $fieldLabel);

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

    private static function assertNotGif(string $path, string $fieldLabel): void
    {
        $header = @file_get_contents($path, false, null, 0, 6);
        if (! is_string($header) || strlen($header) < 6) {
            return;
        }

        if (str_starts_with($header, 'GIF87a') || str_starts_with($header, 'GIF89a')) {
            throw ValidationException::withMessages([
                $fieldLabel => 'GIF não é permitido para documentos KYC. Envie JPG, PNG, WebP, HEIC/HEIF ou PDF.',
            ]);
        }
    }

    private static function assertRasterImageValid(string $path, string $mime, string $fieldLabel): void
    {
        self::assertRasterMagicBytes($path, $mime, $fieldLabel);

        $size = @getimagesize($path);
        if (! is_array($size) || ! isset($size[0], $size[1])) {
            throw ValidationException::withMessages([
                $fieldLabel => 'O arquivo não é uma imagem válida. Use JPG, PNG ou WebP.',
            ]);
        }

        $width = (int) $size[0];
        $height = (int) $size[1];

        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT) {
            throw ValidationException::withMessages([
                $fieldLabel => 'A imagem é pequena demais. Envie uma foto nítida do documento (mínimo '.self::MIN_WIDTH.'×'.self::MIN_HEIGHT.' px).',
            ]);
        }

        if ($width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            throw ValidationException::withMessages([
                $fieldLabel => 'A imagem é grande demais. Reduza a resolução ou envie outra foto do documento.',
            ]);
        }

        if ($width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                $fieldLabel => 'A imagem tem resolução excessiva. Envie uma foto do documento com resolução menor.',
            ]);
        }

        if (function_exists('imagecreatefromstring')) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                throw ValidationException::withMessages([
                    $fieldLabel => 'Não foi possível ler o arquivo enviado.',
                ]);
            }

            $image = @imagecreatefromstring($contents);
            if ($image === false) {
                throw ValidationException::withMessages([
                    $fieldLabel => 'A imagem está corrompida ou é inválida. Envie outra foto do documento.',
                ]);
            }

            imagedestroy($image);
        }
    }

    private static function assertRasterMagicBytes(string $path, string $mime, string $fieldLabel): void
    {
        $header = @file_get_contents($path, false, null, 0, 12);
        if (! is_string($header) || $header === '') {
            throw ValidationException::withMessages([
                $fieldLabel => 'O arquivo não é uma imagem válida. Use JPG, PNG ou WebP.',
            ]);
        }

        $valid = match ($mime) {
            'image/jpeg' => strlen($header) >= 3
                && ord($header[0]) === 0xFF
                && ord($header[1]) === 0xD8
                && ord($header[2]) === 0xFF,
            'image/png' => str_starts_with($header, "\x89PNG\r\n\x1a\n"),
            'image/webp' => strlen($header) >= 12
                && str_starts_with($header, 'RIFF')
                && substr($header, 8, 4) === 'WEBP',
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                $fieldLabel => 'O conteúdo do arquivo não corresponde ao formato declarado. Use JPG, PNG ou WebP.',
            ]);
        }
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
