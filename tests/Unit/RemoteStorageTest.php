<?php

namespace Tests\Unit;

use App\Support\RemoteStorage;
use Tests\TestCase;

class RemoteStorageTest extends TestCase
{
    public function test_build_public_url_collapses_double_slashes_in_object_key(): void
    {
        $url = RemoteStorage::buildPublicUrl(
            'https://r2.valuxpay.com',
            'white-label//IWypmUW8hfEgkoo1o1iPCcgxMiFuhAHo19TjiLTy.png'
        );

        $this->assertSame(
            'https://r2.valuxpay.com/white-label/IWypmUW8hfEgkoo1o1iPCcgxMiFuhAHo19TjiLTy.png',
            $url
        );
    }

    public function test_normalize_stored_object_reference_fixes_absolute_url_with_double_slash(): void
    {
        $normalized = RemoteStorage::normalizeStoredObjectReference(
            'https://r2.valuxpay.com/white-label//logo.png'
        );

        $this->assertSame(
            'https://r2.valuxpay.com/white-label/logo.png',
            $normalized
        );
    }
}
