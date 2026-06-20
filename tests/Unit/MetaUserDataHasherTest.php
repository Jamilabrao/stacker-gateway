<?php

namespace Tests\Unit;

use App\Services\Meta\MetaUserDataHasher;
use Tests\TestCase;

class MetaUserDataHasherTest extends TestCase
{
    public function test_email_is_lowercased_and_hashed(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashEmail('Test@Example.COM');
        $b = $hasher->hashEmail('test@example.com');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a ?? ''));
    }

    public function test_phone_strips_non_digits_before_hash(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashPhone('(11) 98888-7777');
        $b = $hasher->hashPhone('11988887777');

        $this->assertSame($a, $b);
    }

    public function test_country_uses_iso2(): void
    {
        $hasher = new MetaUserDataHasher;
        $a = $hasher->hashCountry('BR');
        $b = $hasher->hashCountry('br');

        $this->assertSame($a, $b);
    }
}
