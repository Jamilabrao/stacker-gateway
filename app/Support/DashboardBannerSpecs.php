<?php

namespace App\Support;

final class DashboardBannerSpecs
{
    public const VARIANT_DESKTOP = 'desktop';

    public const VARIANT_MOBILE = 'mobile';

    /** @var array{width:int,height:int} */
    public const DESKTOP = ['width' => 1600, 'height' => 320];

    /** @var array{width:int,height:int} */
    public const MOBILE = ['width' => 1200, 'height' => 420];

    /**
     * @return array{width:int,height:int}
     */
    public static function dimensionsFor(string $variant): array
    {
        return match ($variant) {
            self::VARIANT_MOBILE => self::MOBILE,
            default => self::DESKTOP,
        };
    }

    public static function labelFor(string $variant): string
    {
        $dims = self::dimensionsFor($variant);

        return $dims['width'].'×'.$dims['height'].' px';
    }

    public static function validateSize(int $width, int $height, string $variant): bool
    {
        $expected = self::dimensionsFor($variant);

        return $width === $expected['width'] && $height === $expected['height'];
    }

    public static function mismatchMessage(int $width, int $height, string $variant): string
    {
        $label = self::labelFor($variant);
        $name = $variant === self::VARIANT_MOBILE ? 'mobile' : 'desktop';

        return "A imagem {$name} deve ter exatamente {$label} (recebido: {$width}×{$height}).";
    }

    /**
     * @return array<string, array{width:int,height:int,label:string}>
     */
    public static function toFrontendSpecs(): array
    {
        return [
            self::VARIANT_DESKTOP => [
                'width' => self::DESKTOP['width'],
                'height' => self::DESKTOP['height'],
                'label' => self::labelFor(self::VARIANT_DESKTOP),
            ],
            self::VARIANT_MOBILE => [
                'width' => self::MOBILE['width'],
                'height' => self::MOBILE['height'],
                'label' => self::labelFor(self::VARIANT_MOBILE),
            ],
        ];
    }
}
