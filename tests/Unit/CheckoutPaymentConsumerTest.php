<?php

namespace Tests\Unit;

use App\Support\CheckoutPaymentConsumer;
use Tests\TestCase;

class CheckoutPaymentConsumerTest extends TestCase
{
    public function test_uses_valid_cpf_when_checksum_is_correct(): void
    {
        $consumer = CheckoutPaymentConsumer::build([
            'name' => 'Maria',
            'email' => 'maria@test.com',
            'cpf' => '529.982.247-25',
            'phone' => '11999998888',
        ], 7);

        $this->assertSame('52998224725', $consumer['document']);
        $this->assertSame('Maria', $consumer['name']);
    }

    public function test_uses_stable_fake_document_when_cpf_checksum_is_invalid(): void
    {
        $first = CheckoutPaymentConsumer::build([
            'name' => 'João',
            'email' => 'joao@test.com',
            'cpf' => '11111111111',
        ], 42);

        $second = CheckoutPaymentConsumer::build([
            'name' => 'João',
            'email' => 'joao@test.com',
            'cpf' => '11111111111',
        ], 42);

        $this->assertSame($first['document'], $second['document']);
        $this->assertNotSame('11111111111', $first['document']);
    }
}
