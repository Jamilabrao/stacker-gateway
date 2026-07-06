<?php

namespace Tests\Feature;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use App\Services\Med\MedDefenseDossierService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedDefenseDossierTest extends TestCase
{
    public function test_generates_pdf_and_stores_path(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);

        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_TENANT,
            'cajupay_dispute_id' => 'dossier-test-1',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 4990,
            'reason' => 'Contestação do pagador',
            'opened_at' => now(),
        ]);

        $service = app(MedDefenseDossierService::class);
        $path = $service->generate($dispute);
        $dispute->refresh();

        $this->assertNotNull($dispute->defense_dossier_path);
        $this->assertStringContainsString('med-dossiers/', $path);
        $this->assertTrue(Storage::disk('local')->exists($dispute->defense_dossier_path));

        $downloadPath = $service->downloadPath($dispute);
        $this->assertNotNull($downloadPath);
        $this->assertFileExists($downloadPath);
    }

    public function test_platform_can_download_generated_dossier(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $product = $this->createTestProduct(['tenant_id' => (int) $seller->id]);

        $order = Order::create([
            'tenant_id' => (int) $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $dispute = MedDispute::create([
            'order_id' => $order->id,
            'tenant_id' => (int) $seller->id,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'cajupay_dispute_id' => 'dossier-download-test',
            'status' => MedDispute::STATUS_OPEN,
            'amount_cents' => 4990,
            'reason' => 'Contestação do pagador',
            'opened_at' => now(),
        ]);

        app(MedDefenseDossierService::class)->generate($dispute);

        $response = $this->actingAs($admin)->get(route('plataforma.disputas.download-dossier', $dispute));

        $response->assertOk();
        $response->assertDownload('med-dossie-'.$dispute->id.'.pdf');
    }
}
