<?php

namespace Tests\Unit\Shipping;

use App\Models\ShippingRule;
use App\Services\Shipping\ShippingRuleMatcher;
use PHPUnit\Framework\TestCase;

class ShippingRuleMatcherTest extends TestCase
{
    private ShippingRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ShippingRuleMatcher;
    }

    public function test_matches_all_brazil(): void
    {
        $rule = new ShippingRule([
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_ALL,
            'match_config' => [],
        ]);

        $this->assertTrue($this->matcher->matches($rule, '01310100', null));
    }

    public function test_matches_state(): void
    {
        $rule = new ShippingRule([
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_STATE,
            'match_config' => ['states' => ['SP', 'RJ']],
        ]);

        $this->assertTrue($this->matcher->matches($rule, '01310100', ['uf' => 'SP', 'city' => 'São Paulo']));
        $this->assertFalse($this->matcher->matches($rule, '01310100', ['uf' => 'MG', 'city' => 'Belo Horizonte']));
    }

    public function test_matches_city_normalized(): void
    {
        $rule = new ShippingRule([
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_CITY,
            'match_config' => ['items' => [['uf' => 'SP', 'city' => 'São Paulo']]],
        ]);

        $this->assertTrue($this->matcher->matches($rule, '01310100', ['uf' => 'SP', 'city' => 'são paulo']));
        $this->assertFalse($this->matcher->matches($rule, '01310100', ['uf' => 'SP', 'city' => 'Campinas']));
    }

    public function test_matches_cep_range(): void
    {
        $rule = new ShippingRule([
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_CEP_RANGE,
            'match_config' => ['from' => '01000000', 'to' => '05999999'],
        ]);

        $this->assertTrue($this->matcher->matches($rule, '01310100', null));
        $this->assertFalse($this->matcher->matches($rule, '80010000', null));
    }

    public function test_matches_cep_prefix(): void
    {
        $rule = new ShippingRule([
            'is_active' => true,
            'match_type' => ShippingRule::MATCH_CEP_PREFIX,
            'match_config' => ['prefixes' => ['01', '02']],
        ]);

        $this->assertTrue($this->matcher->matches($rule, '01310100', null));
        $this->assertFalse($this->matcher->matches($rule, '30130100', null));
    }

    public function test_inactive_rule_never_matches(): void
    {
        $rule = new ShippingRule([
            'is_active' => false,
            'match_type' => ShippingRule::MATCH_ALL,
            'match_config' => [],
        ]);

        $this->assertFalse($this->matcher->matches($rule, '01310100', null));
    }
}
