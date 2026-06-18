<?php

namespace Tests\Unit;

use App\Models\Product;
use Tests\TestCase;

class ProductNormalizePixelIdTest extends TestCase
{
    public function test_normalize_pixel_id_strips_non_digits(): void
    {
        $this->assertSame('123456789012345', Product::normalizePixelIdString('ID: 123456789012345'));
    }

    public function test_normalize_conversion_pixel_block_sanitizes_meta_pixel_id(): void
    {
        $block = Product::normalizeConversionPixelBlock([
            'enabled' => true,
            'entries' => [
                ['id' => 'x', 'pixel_id' => 'Pixel 9876543210987', 'access_token' => ''],
            ],
        ], 'meta');

        $this->assertSame('9876543210987', $block['entries'][0]['pixel_id'] ?? null);
    }
}
