<?php

namespace Tests\Unit;

use App\Support\CnpjLookup;
use Tests\TestCase;

class CnpjLookupTest extends TestCase
{
    public function test_names_match_ignores_case_accents_and_punctuation(): void
    {
        $this->assertTrue(CnpjLookup::namesMatch('José da Silva Ltda.', 'JOSE DA SILVA LTDA'));
        $this->assertFalse(CnpjLookup::namesMatch('José da Silva Ltda.', 'Outra Empresa LTDA'));
        $this->assertFalse(CnpjLookup::namesMatch('', 'Empresa'));
    }

    public function test_irregular_situations(): void
    {
        $this->assertTrue(CnpjLookup::isIrregular('BAIXADA'));
        $this->assertTrue(CnpjLookup::isIrregular('inapta'));
        $this->assertTrue(CnpjLookup::isIrregular('SUSPENSA'));
        $this->assertTrue(CnpjLookup::isIrregular('NULA'));
        $this->assertFalse(CnpjLookup::isIrregular('ATIVA'));
        $this->assertFalse(CnpjLookup::isIrregular(''));
    }
}
