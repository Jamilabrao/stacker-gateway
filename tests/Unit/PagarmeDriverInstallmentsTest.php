<?php

namespace Tests\Unit;

use App\Gateways\Pagarme\PagarmeDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PagarmeDriverInstallmentsTest extends TestCase
{
    public function test_create_card_payment_sends_credit_card_installments(): void
    {
        Http::fake([
            'https://api.pagar.me/core/v5/orders' => Http::response([
                'charges' => [[
                    'id' => 'ch_test_installments',
                    'status' => 'paid',
                ]],
            ], 200),
        ]);

        $driver = new PagarmeDriver;
        $result = $driver->createCardPayment(
            ['secret_key' => 'sk_test'],
            97.90,
            [
                'name' => 'Cliente Teste',
                'email' => 'cliente@example.com',
                'document' => '52998224725',
                'phone' => '11999999999',
                'address' => [
                    'zip_code' => '01310100',
                    'street_name' => 'Avenida Paulista',
                    'street_number' => '1000',
                    'neighborhood' => 'Bela Vista',
                    'city' => 'Sao Paulo',
                    'federal_unit' => 'SP',
                ],
            ],
            '42',
            [
                'payment_token' => json_encode(['card_token' => 'tok_abc', 'installments' => 3]),
                'installments' => 3,
            ]
        );

        $this->assertSame('ch_test_installments', $result['transaction_id']);
        $this->assertSame('paid', $result['status']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/orders')
                && ($body['payments'][0]['credit_card']['installments'] ?? null) === 3;
        });
    }
}
