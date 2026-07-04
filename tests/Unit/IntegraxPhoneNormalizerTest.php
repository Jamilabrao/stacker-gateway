<?php

namespace Tests\Unit;

use App\Services\Integrax\IntegraxService;
use Tests\TestCase;

class IntegraxPhoneNormalizerTest extends TestCase
{
    public function test_normalizes_brazilian_phone_with_country_code(): void
    {
        $service = new IntegraxService;

        $this->assertSame('5511999887766', $service->normalizePhone('5511999887766'));
    }

    public function test_normalizes_phone_without_country_code(): void
    {
        $service = new IntegraxService;

        $this->assertSame('5511999887766', $service->normalizePhone('(11) 99988-7766'));
    }

    public function test_returns_null_for_invalid_phone(): void
    {
        $service = new IntegraxService;

        $this->assertNull($service->normalizePhone('123'));
        $this->assertNull($service->normalizePhone(''));
    }

    public function test_render_message_replaces_placeholders(): void
    {
        $service = new IntegraxService;

        $message = $service->renderMessage('Oi {nome}, {produto} por {valor}', [
            'nome' => 'Maria',
            'produto' => 'Curso',
            'valor' => 'R$ 10,00',
        ]);

        $this->assertSame('Oi Maria, Curso por R$ 10,00', $message);
    }

    public function test_assert_message_length_rejects_over_160_chars(): void
    {
        $service = new IntegraxService;

        $this->expectException(\InvalidArgumentException::class);
        $service->assertMessageLength(str_repeat('a', 161));
    }
}
