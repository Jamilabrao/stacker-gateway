<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\MemberAccessGrantService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManualMemberAccessGrantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    private function createSeller(): User
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $owner->forceFill(['tenant_id' => $owner->id])->save();

        return $owner->fresh();
    }

    private function createSubscriptionProduct(User $owner): array
    {
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'mag'.substr(uniqid('', true), -8),
            'slug' => 'mag-'.substr(uniqid('', true), -8),
        ]);

        $monthly = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pm-'.substr(uniqid('', true), -8),
            'position' => 1,
        ]);

        $lifetime = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Vitalício',
            'price' => 297,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_LIFETIME,
            'checkout_slug' => 'pl-'.substr(uniqid('', true), -8),
            'position' => 2,
        ]);

        return [$product->fresh(), $monthly, $lifetime];
    }

    public function test_manual_aluno_store_creates_lifetime_subscription_and_grants_access(): void
    {
        $owner = $this->createSeller();
        [$product, $monthly, $lifetime] = $this->createSubscriptionProduct($owner);

        $email = 'aluno.manual.'.uniqid().'@example.com';

        $resp = $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Manual',
            'email' => $email,
            'password' => 'senha123',
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ]);

        $resp->assertOk()->assertJsonFragment(['success' => true]);

        $aluno = User::where('email', $email)->first();

        $this->assertNotNull($aluno);
        $this->assertTrue($product->users()->where('user_id', $aluno->id)->exists());
        $this->assertTrue($product->hasMemberAreaAccess($aluno));

        $subscription = Subscription::query()
            ->where('user_id', $aluno->id)
            ->where('product_id', $product->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame($lifetime->id, $subscription->subscription_plan_id);
        $this->assertNull($subscription->current_period_end);
        $this->assertNotSame($monthly->id, $subscription->subscription_plan_id);
    }

    public function test_member_access_grant_service_prefers_lifetime_plan(): void
    {
        $owner = $this->createSeller();
        [$product, , $lifetime] = $this->createSubscriptionProduct($owner);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        app(MemberAccessGrantService::class)->grant($aluno, $product);

        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $lifetime->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_member_builder_store_new_aluno_grants_subscription_access(): void
    {
        $owner = $this->createSeller();
        [$product, , $lifetime] = $this->createSubscriptionProduct($owner);

        $email = 'builder.aluno.'.uniqid().'@example.com';

        $resp = $this->actingAs($owner)->postJson(
            route('member-builder.alunos.store', $product),
            [
                'name' => 'Aluno Builder',
                'email' => $email,
                'password' => 'senha123',
            ]
        );

        $resp->assertOk();

        $aluno = User::where('email', $email)->first();
        $this->assertNotNull($aluno);
        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $lifetime->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_one_time_product_grant_does_not_create_subscription(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'ot'.substr(uniqid('', true), -8),
            'slug' => 'ot-'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        app(MemberAccessGrantService::class)->grant($aluno, $product);

        $this->assertTrue($product->hasMemberAreaAccess($aluno));
        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_store_reuses_existing_cliente_without_changing_password(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'ru'.substr(uniqid('', true), -8),
            'slug' => 'ru-'.substr(uniqid('', true), -8),
        ]);

        $email = 'reuse.'.uniqid().'@example.com';
        $aluno = User::factory()->create([
            'name' => 'Cliente Existente',
            'email' => $email,
            'password' => bcrypt('senha-original'),
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $originalHash = $aluno->password;

        $resp = $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Nome Ignorado',
            'email' => strtoupper($email),
            'password' => 'nova-senha-errada',
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ]);

        $resp->assertOk()
            ->assertJsonFragment(['success' => true, 'created' => false]);

        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->count());
        $aluno->refresh();
        $this->assertSame($originalHash, $aluno->password);
        $this->assertSame('Cliente Existente', $aluno->name);
        $this->assertSame(User::ROLE_CLIENTE, $aluno->role);
        $this->assertNull($aluno->tenant_id);
        $this->assertTrue($product->users()->where('user_id', $aluno->id)->exists());
    }

    public function test_store_blocks_non_cliente_email(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'bl'.substr(uniqid('', true), -8),
            'slug' => 'bl-'.substr(uniqid('', true), -8),
        ]);

        $resp = $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Tentativa',
            'email' => $owner->email,
            'password' => 'senha123',
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertFalse($product->users()->where('user_id', $owner->id)->exists());
    }

    public function test_destroy_revokes_tenant_access_but_keeps_user_and_other_products(): void
    {
        $owner = $this->createSeller();
        $otherOwner = $this->createSeller();

        $myProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'my'.substr(uniqid('', true), -8),
            'slug' => 'my-'.substr(uniqid('', true), -8),
        ]);
        $otherProduct = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $otherOwner->id,
            'checkout_slug' => 'ot'.substr(uniqid('', true), -8),
            'slug' => 'ot-'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        app(MemberAccessGrantService::class)->grant($aluno, $myProduct);
        app(MemberAccessGrantService::class)->grant($aluno, $otherProduct);

        $resp = $this->actingAs($owner)->deleteJson(route('alunos.destroy', $aluno));
        $resp->assertOk()->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('users', ['id' => $aluno->id]);
        $this->assertFalse($myProduct->users()->where('user_id', $aluno->id)->exists());
        $this->assertTrue($otherProduct->users()->where('user_id', $aluno->id)->exists());
    }

    public function test_store_can_rematriculate_after_destroy(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'rm'.substr(uniqid('', true), -8),
            'slug' => 'rm-'.substr(uniqid('', true), -8),
        ]);

        $email = 'remat.'.uniqid().'@example.com';
        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Remat',
            'email' => $email,
            'password' => 'senha123',
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ])->assertOk();

        $aluno = User::where('email', $email)->firstOrFail();
        $this->actingAs($owner)->deleteJson(route('alunos.destroy', $aluno))->assertOk();
        $this->assertFalse($product->users()->where('user_id', $aluno->id)->exists());

        $this->actingAs($owner)->postJson(route('alunos.store'), [
            'name' => 'Aluno Remat',
            'email' => $email,
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ])->assertOk()->assertJsonFragment(['created' => false]);

        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertTrue($product->users()->where('user_id', $aluno->id)->exists());
    }

    public function test_member_builder_reuses_existing_cliente(): void
    {
        $owner = $this->createSeller();
        [$product] = $this->createSubscriptionProduct($owner);

        $email = 'builder.reuse.'.uniqid().'@example.com';
        $aluno = User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $resp = $this->actingAs($owner)->postJson(
            route('member-builder.alunos.store', $product),
            [
                'name' => 'Ignorado',
                'email' => $email,
            ]
        );

        $resp->assertOk()->assertJsonFragment(['created' => false]);
        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertTrue($product->hasMemberAreaAccess($aluno->fresh()));
    }

    private function createTeamMemberForProducts(User $owner, array $allowedProductIds): User
    {
        $role = TeamRole::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Equipe produtos '.uniqid(),
            'permissions' => [
                'dashboard.view' => true,
                'vendas.view' => true,
                'produtos.view' => true,
                'relatorios.view' => false,
                'integracoes.view' => false,
                'email_marketing.view' => false,
                'api_pagamentos.view' => false,
                'configuracoes.view' => false,
                'equipe.manage' => false,
            ],
        ]);
        $role->products()->sync($allowedProductIds);

        return User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => $owner->tenant_id,
            'team_role_id' => $role->id,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
        ]);
    }

    public function test_team_destroy_revokes_only_allowed_products(): void
    {
        $owner = $this->createSeller();
        $productA = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'ta'.substr(uniqid('', true), -8),
            'slug' => 'ta-'.substr(uniqid('', true), -8),
        ]);
        $productB = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'tb'.substr(uniqid('', true), -8),
            'slug' => 'tb-'.substr(uniqid('', true), -8),
        ]);

        $team = $this->createTeamMemberForProducts($owner, [$productA->id]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $grant = app(MemberAccessGrantService::class);
        $grant->grant($aluno, $productA);
        $grant->grant($aluno, $productB);

        $this->actingAs($team)
            ->deleteJson(route('alunos.destroy', $aluno))
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('users', ['id' => $aluno->id]);
        $this->assertFalse($productA->users()->where('user_id', $aluno->id)->exists());
        $this->assertTrue($productB->users()->where('user_id', $aluno->id)->exists());
    }

    public function test_team_remove_product_allows_only_permitted_products(): void
    {
        $owner = $this->createSeller();
        $productA = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'ra'.substr(uniqid('', true), -8),
            'slug' => 'ra-'.substr(uniqid('', true), -8),
        ]);
        $productB = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'rb'.substr(uniqid('', true), -8),
            'slug' => 'rb-'.substr(uniqid('', true), -8),
        ]);

        $team = $this->createTeamMemberForProducts($owner, [$productA->id]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $grant = app(MemberAccessGrantService::class);
        $grant->grant($aluno, $productA);
        $grant->grant($aluno, $productB);

        $this->actingAs($team)
            ->deleteJson(route('alunos.remove-product', [$aluno, $productA]))
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertFalse($productA->users()->where('user_id', $aluno->id)->exists());

        $this->actingAs($team)
            ->deleteJson(route('alunos.remove-product', [$aluno, $productB]))
            ->assertForbidden();

        $this->assertTrue($productB->users()->where('user_id', $aluno->id)->exists());
        $this->assertDatabaseHas('users', ['id' => $aluno->id]);
    }

    public function test_grant_is_idempotent_on_product_user(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'id'.substr(uniqid('', true), -8),
            'slug' => 'id-'.substr(uniqid('', true), -8),
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $grant = app(MemberAccessGrantService::class);
        $grant->grant($aluno, $product);
        $grant->grant($aluno, $product);

        $this->assertSame(
            1,
            (int) DB::table('product_user')
                ->where('user_id', $aluno->id)
                ->where('product_id', $product->id)
                ->count()
        );
    }

    public function test_import_csv_mixed_rows_creates_links_and_skips_non_cliente(): void
    {
        $owner = $this->createSeller();
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'tenant_id' => $owner->id,
            'checkout_slug' => 'im'.substr(uniqid('', true), -8),
            'slug' => 'im-'.substr(uniqid('', true), -8),
        ]);

        $existingEmail = 'exist.'.uniqid().'@example.com';
        $existing = User::factory()->create([
            'name' => 'Cliente CSV Existente',
            'email' => $existingEmail,
            'password' => Hash::make('senha-original'),
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $existingHash = $existing->password;

        $alreadyEmail = 'already.'.uniqid().'@example.com';
        $already = User::factory()->create([
            'name' => 'Ja Matriculado',
            'email' => $alreadyEmail,
            'password' => Hash::make('senha-matriculado'),
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);
        $alreadyHash = $already->password;
        app(MemberAccessGrantService::class)->grant($already, $product);

        $nonClienteEmail = $owner->email;
        $newEmail = 'novo.'.uniqid().'@example.com';

        $csv = "nome;email;senha\n"
            ."Novo Aluno;{$newEmail};senha123\n"
            ."Nome Ignorado;{$existingEmail};\n"
            ."Outro Nome;{$alreadyEmail};\n"
            ."Admin Fake;{$nonClienteEmail};senha123\n";

        $file = UploadedFile::fake()->createWithContent('alunos.csv', $csv);

        $resp = $this->actingAs($owner)->postJson(route('alunos.import'), [
            'file' => $file,
            'product_ids' => [$product->id],
            'send_access_email' => false,
        ]);

        $resp->assertOk()
            ->assertJsonFragment([
                'success' => true,
                'created' => 1,
                'linked' => 2,
                'skipped' => 1,
            ]);

        $this->assertNotNull(User::where('email', mb_strtolower($newEmail))->first());
        $this->assertTrue($product->users()->where('user_id', User::where('email', mb_strtolower($newEmail))->value('id'))->exists());

        $existing->refresh();
        $this->assertSame('Cliente CSV Existente', $existing->name);
        $this->assertSame($existingHash, $existing->password);
        $this->assertSame(User::ROLE_CLIENTE, $existing->role);
        $this->assertNull($existing->tenant_id);
        $this->assertTrue($product->users()->where('user_id', $existing->id)->exists());

        $already->refresh();
        $this->assertSame('Ja Matriculado', $already->name);
        $this->assertSame($alreadyHash, $already->password);
        $this->assertSame(User::ROLE_CLIENTE, $already->role);
        $this->assertSame(
            1,
            (int) DB::table('product_user')
                ->where('user_id', $already->id)
                ->where('product_id', $product->id)
                ->count()
        );

        $this->assertFalse($product->users()->where('user_id', $owner->id)->exists());
        $this->assertSame(User::ROLE_INFOPRODUTOR, $owner->fresh()->role);
    }

    public function test_subscription_grant_revoke_rematriculate_and_isolates_other_product(): void
    {
        $owner = $this->createSeller();
        [$productA, , $lifetimeA] = $this->createSubscriptionProduct($owner);
        [$productB, , $lifetimeB] = $this->createSubscriptionProduct($owner);

        $aluno = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $grant = app(MemberAccessGrantService::class);
        $grant->grant($aluno, $productA);
        $grant->grant($aluno, $productB);

        $this->assertTrue($productA->hasMemberAreaAccess($aluno));
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $productA->id,
            'subscription_plan_id' => $lifetimeA->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $productB->id,
            'subscription_plan_id' => $lifetimeB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $grant->revoke($aluno, $productA);

        $this->assertFalse($productA->users()->where('user_id', $aluno->id)->exists());
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $productA->id,
            'status' => Subscription::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $productB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
        $this->assertTrue($productB->hasMemberAreaAccess($aluno));

        $grant->grant($aluno, $productA);

        $this->assertTrue($productA->hasMemberAreaAccess($aluno));
        $this->assertTrue(
            Subscription::query()
                ->where('user_id', $aluno->id)
                ->where('product_id', $productA->id)
                ->where('status', Subscription::STATUS_ACTIVE)
                ->exists()
        );
        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $aluno->id,
            'product_id' => $productB->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }
}
