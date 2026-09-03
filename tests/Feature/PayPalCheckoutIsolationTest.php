<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Services\PaymentService;
use Tests\TestCase;

class PayPalCheckoutIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('gateway_order', [
            'pix' => ['efi'],
            'card' => ['efi'],
            'boleto' => ['efi'],
            'pix_auto' => [],
        ], null);
    }

    public function test_connected_paypal_does_not_enter_pix_or_card_redundancy(): void
    {
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $efi = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $efi->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $efi->save();

        $paypal = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'paypal',
            'is_connected' => true,
        ]);
        $paypal->setEncryptedCredentials([
            'client_id' => 'paypal_client',
            'client_secret' => 'paypal_secret',
            'sandbox' => true,
        ]);
        $paypal->save();

        $ps = app(PaymentService::class);

        $this->assertNotContains('paypal', $ps->getGatewayOrderForMethod(1, 'pix', $product));
        $this->assertNotContains('paypal', $ps->getGatewayOrderForMethod(1, 'card', $product));
        $this->assertNotContains('paypal', $ps->getGatewayOrderForMethod(1, 'boleto', $product));
        $this->assertContains('paypal', $ps->getGatewayOrderForMethod(1, 'paypal', $product));

        $ids = array_column($ps->availablePaymentMethodsForCheckout($product, null, null), 'id');
        $this->assertContains('pix', $ids);
        $this->assertContains('paypal', $ids);

        $global = $ps->globallyAvailablePaymentMethodKeys($product, null);
        $this->assertTrue($global['pix']);
        $this->assertTrue($global['paypal']);
    }

    public function test_paypal_hidden_when_credential_missing(): void
    {
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $efi = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'efi',
            'is_connected' => true,
        ]);
        $efi->setEncryptedCredentials(['payee_code' => '123', 'sandbox' => true]);
        $efi->save();

        $ps = app(PaymentService::class);
        $ids = array_column($ps->availablePaymentMethodsForCheckout($product, null, null), 'id');
        $this->assertNotContains('paypal', $ids);
        $this->assertContains('pix', $ids);
    }
}
