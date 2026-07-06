<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductUpdateGeneralTabTest extends TestCase
{
    private function createApprovedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    public function test_general_tab_update_persists_price_and_image_without_payment_methods_payload(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        Storage::fake('public');

        $seller = $this->createApprovedSeller();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 3.99,
            'currency' => 'BRL',
            'billing_type' => Product::BILLING_ONE_TIME,
            'checkout_config' => [
                'payment_methods_enabled' => [
                    'pix' => false,
                    'card' => false,
                    'boleto' => false,
                ],
            ],
        ]);

        $image = UploadedFile::fake()->image('produto.jpg', 400, 400);

        $response = $this->actingAs($seller)->post(route('produtos.update', $product->id), [
            '_method' => 'PUT',
            'name' => $product->name,
            'description' => 'Descrição atualizada',
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => 10,
            'currency' => 'BRL',
            'is_active' => true,
            'refund_policy_days' => '',
            'image' => $image,
        ]);

        $response->assertRedirect(route('produtos.edit', $product->id));

        $product->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $product->price, 0.001);
        $this->assertNotNull($product->image);
        $this->assertSame('Descrição atualizada', $product->description);
    }

    public function test_general_tab_put_updates_brl_price(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'price' => 3.99,
            'currency' => 'BRL',
        ]);

        $response = $this->actingAs($seller)->put(route('produtos.update', $product->id), [
            'name' => $product->name,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => 25.5,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertRedirect();

        $product->refresh();
        $this->assertEqualsWithDelta(25.5, (float) $product->price, 0.001);
    }
}
