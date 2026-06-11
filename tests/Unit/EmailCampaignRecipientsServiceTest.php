<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\EmailCampaignRecipientsService;
use App\Support\EmailCampaignTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailCampaignRecipientsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_includes_infoprodutors_when_requested(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => null,
            'email' => 'vendedor@example.com',
            'account_status' => 'approved',
        ]);

        $service = app(EmailCampaignRecipientsService::class);
        $recipients = $service->getRecipients(null, [
            'include_customers' => false,
            'include_infoprodutors' => true,
        ]);

        $this->assertSame(1, $recipients->count());
        $this->assertSame('vendedor@example.com', $recipients->first()['email']);
        $this->assertSame('infoprodutor', $recipients->first()['type']);
        $this->assertSame($seller->id, $recipients->first()['user_id']);
    }

    public function test_customers_and_infoprodutors_are_merged_without_duplicate_email(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'mesmo@example.com',
            'account_status' => 'approved',
        ]);

        $product = $this->createTestProduct();
        Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'mesmo@example.com',
        ]);

        $service = app(EmailCampaignRecipientsService::class);
        $recipients = $service->getRecipients(null, [
            'include_customers' => true,
            'include_infoprodutors' => true,
            'all_customers' => true,
        ]);

        $this->assertSame(1, $recipients->count());
    }

    public function test_deleted_customer_is_not_included(): void
    {
        $product = $this->createTestProduct();
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'email' => 'comprador@example.com',
            'account_status' => 'approved',
        ]);

        Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'comprador@example.com',
        ]);

        $service = app(EmailCampaignRecipientsService::class);
        $before = $service->getRecipients(null, [
            'include_customers' => true,
            'include_infoprodutors' => false,
            'all_customers' => true,
        ]);

        $this->assertSame(1, $before->count());
        $this->assertSame('comprador@example.com', $before->first()['email']);

        $buyer->delete();

        $after = $service->getRecipients(null, [
            'include_customers' => true,
            'include_infoprodutors' => false,
            'all_customers' => true,
        ]);

        $this->assertSame(0, $after->count());
    }

    public function test_deleted_infoprodutor_is_not_included(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => null,
            'email' => 'vendedor-removido@example.com',
            'account_status' => 'approved',
        ]);

        $service = app(EmailCampaignRecipientsService::class);
        $before = $service->getRecipients(null, [
            'include_customers' => false,
            'include_infoprodutors' => true,
        ]);

        $this->assertSame(1, $before->count());

        $seller->delete();

        $after = $service->getRecipients(null, [
            'include_customers' => false,
            'include_infoprodutors' => true,
        ]);

        $this->assertSame(0, $after->count());
    }

    public function test_template_wraps_plain_text_and_extracts_it_back(): void
    {
        $message = "Linha um.\n\nLinha dois.";
        $html = EmailCampaignTemplate::wrapContent($message, '#c8fa64');

        $this->assertStringContainsString('data-campaign-body="1"', $html);
        $this->assertStringContainsString('Olá, {nome}!', $html);
        $this->assertStringContainsString('#c8fa64', $html);
        $this->assertSame($message, EmailCampaignTemplate::extractPlainText($html));
    }
}
