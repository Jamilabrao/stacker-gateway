<?php

namespace Tests\Unit;

use App\Support\UploadLimits;
use Tests\TestCase;

class UploadLimitsTest extends TestCase
{
    public function test_member_builder_defaults_allow_fifty_mb_pdf(): void
    {
        config([
            'member_builder_uploads.pdf_max_kb' => 51200,
            'member_builder_uploads.image_max_kb' => 10240,
        ]);

        $this->assertSame(51200, UploadLimits::memberBuilderPdfMaxKb());
        $this->assertSame(50, UploadLimits::memberBuilderPdfMaxMb());
        $this->assertSame(50, UploadLimits::memberBuilderForFrontend()['pdf_max_mb']);
    }

    public function test_php_upload_error_message_mentions_limit(): void
    {
        $msg = UploadLimits::messageForPhpUploadError(UPLOAD_ERR_INI_SIZE, 50);
        $this->assertStringContainsString('50', $msg);
        $this->assertStringContainsString('MB', $msg);
    }
}
