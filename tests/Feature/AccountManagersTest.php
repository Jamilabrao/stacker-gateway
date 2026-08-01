<?php

namespace Tests\Feature;

use App\Models\AccountManager;
use App\Models\AccountManagerAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountManagerAssignmentService;
use App\Support\AccountManagerSettings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountManagersTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(array $extra = []): User
    {
        $seller = User::factory()->create(array_merge([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'password' => Hash::make('password'),
        ], $extra));
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function createManager(array $extra = []): AccountManager
    {
        return AccountManager::query()->create(array_merge([
            'name' => 'Gerente Teste',
            'email' => 'gerente'.uniqid('', true).'@example.com',
            'phone' => '5511999990001',
            'is_active' => true,
            'show_email' => true,
            'show_phone' => true,
            'show_whatsapp' => true,
            'show_photo' => true,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('account_managers') || ! Schema::hasColumn('users', 'account_manager_id')) {
            $this->markTestSkipped('Migração de gerentes de conta não aplicada.');
        }
    }

    public function test_admin_can_create_account_manager(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('plataforma.gerentes-conta.store'), [
                'name' => 'Ana Conta',
                'email' => 'ana.conta@example.com',
                'phone' => '(11) 98888-7777',
                'is_active' => true,
                'show_email' => true,
                'show_phone' => true,
                'show_whatsapp' => true,
                'show_photo' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('account_managers', [
            'email' => 'ana.conta@example.com',
            'name' => 'Ana Conta',
            'is_active' => 1,
        ]);
    }

    public function test_admin_can_assign_manager_to_merchant(): void
    {
        $admin = $this->platformAdmin();
        $seller = $this->seller();
        $manager = $this->createManager();

        $this->actingAs($admin)
            ->post(route('plataforma.usuarios.account-manager.assign', $seller), [
                'account_manager_id' => $manager->id,
                'reason' => 'Atribuição manual de teste',
            ])
            ->assertRedirect();

        $this->assertSame($manager->id, (int) $seller->fresh()->account_manager_id);
        $this->assertDatabaseHas('account_manager_assignments', [
            'merchant_user_id' => $seller->id,
            'account_manager_id' => $manager->id,
            'source' => AccountManagerAssignment::SOURCE_MANUAL,
        ]);
    }

    public function test_bulk_transfer_moves_merchants(): void
    {
        $admin = $this->platformAdmin();
        $from = $this->createManager(['name' => 'Origem', 'email' => 'origem@example.com']);
        $to = $this->createManager(['name' => 'Destino', 'email' => 'destino@example.com']);
        $s1 = $this->seller(['email' => 's1@example.com']);
        $s2 = $this->seller(['email' => 's2@example.com']);

        $service = app(AccountManagerAssignmentService::class);
        $service->assign($s1, $from, $admin, AccountManagerAssignment::SOURCE_MANUAL);
        $service->assign($s2, $from, $admin, AccountManagerAssignment::SOURCE_MANUAL);

        $this->actingAs($admin)
            ->post(route('plataforma.gerentes-conta.transfer', $from), [
                'target_manager_id' => $to->id,
                'transfer_all' => true,
            ])
            ->assertRedirect();

        $this->assertSame($to->id, (int) $s1->fresh()->account_manager_id);
        $this->assertSame($to->id, (int) $s2->fresh()->account_manager_id);
    }

    public function test_auto_assign_uses_least_load(): void
    {
        Setting::set(AccountManagerSettings::KEY_MODE, AccountManagerSettings::MODE_LEAST_LOAD, null);

        $light = $this->createManager(['name' => 'Leve', 'email' => 'leve@example.com']);
        $heavy = $this->createManager(['name' => 'Pesado', 'email' => 'pesado@example.com']);
        $admin = $this->platformAdmin();
        $existing = $this->seller(['email' => 'existing@example.com']);
        app(AccountManagerAssignmentService::class)->assign(
            $existing,
            $heavy,
            $admin,
            AccountManagerAssignment::SOURCE_MANUAL
        );

        $newbie = $this->seller(['email' => 'newbie@example.com']);
        app(AccountManagerAssignmentService::class)->autoAssignIfConfigured($newbie);

        $this->assertSame($light->id, (int) $newbie->fresh()->account_manager_id);
    }

    public function test_auto_assign_respects_none_mode(): void
    {
        Setting::set(AccountManagerSettings::KEY_MODE, AccountManagerSettings::MODE_NONE, null);
        $this->createManager();

        $newbie = $this->seller(['email' => 'noauto@example.com']);
        app(AccountManagerAssignmentService::class)->autoAssignIfConfigured($newbie);

        $this->assertNull($newbie->fresh()->account_manager_id);
    }

    public function test_dashboard_shows_account_manager_card_payload(): void
    {
        $manager = $this->createManager([
            'name' => 'Card Visível',
            'show_email' => true,
            'show_whatsapp' => true,
        ]);
        $seller = $this->seller();
        $seller->forceFill(['account_manager_id' => $manager->id])->save();

        $this->actingAs($seller)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->has('account_manager')
                ->where('account_manager.name', 'Card Visível')
            );
    }

    public function test_seller_cannot_access_account_managers_admin(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->get(route('plataforma.gerentes-conta.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_auto_assign_setting(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->put(route('plataforma.settings.update'), [
                'account_manager_auto_assign_mode' => 'none',
                'checkout_translations' => config('checkout_translations'),
                'currencies' => config('products.currencies'),
                'email_provider' => 'smtp',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'smtp_username' => 'user@example.com',
                'smtp_encryption' => 'tls',
                'mail_from_address' => 'noreply@example.com',
                'mail_from_name' => 'Getfy',
            ])
            ->assertRedirect();

        $this->assertSame(AccountManagerSettings::MODE_NONE, AccountManagerSettings::mode());
    }

    public function test_public_card_hides_fields_when_flags_off(): void
    {
        $manager = $this->createManager([
            'show_email' => false,
            'show_phone' => false,
            'show_whatsapp' => false,
            'show_photo' => false,
        ]);
        $seller = $this->seller();
        $seller->forceFill(['account_manager_id' => $manager->id])->save();

        $card = app(AccountManagerAssignmentService::class)->publicCardForMerchant($seller->fresh());

        $this->assertSame($manager->name, $card['name']);
        $this->assertArrayNotHasKey('email', $card);
        $this->assertArrayNotHasKey('phone', $card);
        $this->assertArrayNotHasKey('whatsapp_url', $card);
        $this->assertArrayNotHasKey('photo_url', $card);
    }
}
