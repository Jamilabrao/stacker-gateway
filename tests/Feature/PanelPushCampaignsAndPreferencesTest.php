<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailySalesPushJob;
use App\Jobs\ProcessPanelPushCampaignJob;
use App\Models\Order;
use App\Models\PanelPushCampaign;
use App\Models\PanelPushDailySummaryLog;
use App\Models\User;
use App\Services\DailySalesPushService;
use App\Services\PanelPushCampaignService;
use App\Services\PanelPushService;
use App\Support\DailySalesPushSettings;
use App\Support\UserPushPreferences;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesTestVapidKeys;
use Tests\TestCase;

class PanelPushCampaignsAndPreferencesTest extends TestCase
{
    use UsesTestVapidKeys;

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(): User
    {
        $attrs = [
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'password' => Hash::make('password'),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller = User::factory()->create($attrs);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPushFeatureTests();
        if (! Schema::hasTable('panel_push_campaigns')) {
            $this->markTestSkipped('Migração de campanhas push não aplicada.');
        }
    }

    public function test_admin_can_schedule_campaign_and_idempotent_claim(): void
    {
        Bus::fake([ProcessPanelPushCampaignJob::class]);
        $this->configureTestVapidPush();
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Campanha teste',
                'body' => 'Mensagem de teste agendada',
                'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
                'send_mode' => 'scheduled',
                'scheduled_local' => now('America/Sao_Paulo')->addHour()->format('Y-m-d\TH:i'),
                'timezone' => 'America/Sao_Paulo',
                'confirm_global' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $campaign = PanelPushCampaign::query()->first();
        $this->assertNotNull($campaign);
        $this->assertSame(PanelPushCampaign::STATUS_SCHEDULED, $campaign->status);

        $service = app(PanelPushCampaignService::class);
        // Ainda no futuro: não processa
        $service->process($campaign->id);
        $this->assertSame(PanelPushCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);

        $campaign->forceFill(['scheduled_at' => now('UTC')->subMinute()])->save();
        $service->process($campaign->id);
        $this->assertNotSame(PanelPushCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);

        // Segunda execução não reprocessa
        $status = $campaign->fresh()->status;
        $service->process($campaign->id);
        $this->assertSame($status, $campaign->fresh()->status);
    }

    public function test_cancel_scheduled_campaign(): void
    {
        $admin = $this->platformAdmin();
        $campaign = PanelPushCampaign::query()->create([
            'title' => 'Cancelável',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_SCHEDULED,
            'scheduled_at' => now()->addDay(),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_SCHEDULED,
            'idempotency_key' => 'test-cancel-1',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.campaigns.cancel', $campaign))
            ->assertOk()
            ->assertJsonPath('campaign.status', PanelPushCampaign::STATUS_CANCELLED);
    }

    public function test_seller_push_preferences_and_blocks_event(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->put(route('profile.push-preferences'), [
                'sale_approved' => '0',
                'show_product_name' => '1',
                'show_sale_amount' => '0',
                'sale_amount_mode' => 'net',
                'show_payment_method' => '1',
            ])
            ->assertRedirect(route('profile.index'));

        $this->assertFalse(UserPushPreferences::allowsEvent($seller->id, 'sale_approved'));
        $prefs = UserPushPreferences::forUserId($seller->id);
        $this->assertFalse($prefs['show_sale_amount']);
        $this->assertSame('net', $prefs['sale_amount_mode']);
    }

    public function test_seller_can_prefer_net_sale_amount_in_push(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->from(route('profile.index'))
            ->put(route('profile.push-preferences'), [
                'show_sale_amount' => '1',
                'sale_amount_mode' => 'net',
            ])
            ->assertRedirect(route('profile.index'));

        $prefs = UserPushPreferences::forUserId($seller->id);
        $this->assertTrue($prefs['show_sale_amount']);
        $this->assertSame('net', $prefs['sale_amount_mode']);
    }

    public function test_product_notification_name_used_in_push_body(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Nome Interno',
            'notification_name' => 'Nome Push',
            'checkout_slug' => 'notifname01',
        ]);

        $order = new Order([
            'tenant_id' => $seller->id,
            'amount' => 50,
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', $product);

        $body = $order->saleApprovedPushBody();
        $this->assertStringContainsString('Nome Push', $body);
        $this->assertStringNotContainsString('Nome Interno', $body);
    }

    public function test_daily_summary_does_not_duplicate_and_scopes_tenant(): void
    {
        if (! Schema::hasTable('panel_push_daily_summary_logs') || ! Schema::hasTable('orders')) {
            $this->markTestSkipped('tabelas necessárias');
        }

        DailySalesPushSettings::persist([
            'daily_sales_push_enabled' => true,
            'daily_sales_push_time' => '20:00',
            'daily_sales_push_timezone' => 'America/Sao_Paulo',
            'daily_sales_push_only_when_has_sales' => true,
        ]);

        $seller = $this->seller();
        $other = $this->seller();
        $day = Carbon::now('America/Sao_Paulo')->subDay()->startOfDay();
        $productA = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'dailysuma',
        ]);
        $productB = $this->createTestProduct([
            'tenant_id' => $other->id,
            'checkout_slug' => 'dailysumb',
        ]);

        $orderA = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $productA->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'payment_method' => 'pix',
        ]);
        $orderB = Order::query()->create([
            'tenant_id' => $other->id,
            'user_id' => $other->id,
            'product_id' => $productB->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'payment_method' => 'pix',
        ]);
        // updated_at não é fillable — força o dia de referência do resumo
        Order::query()->whereKey($orderA->id)->update([
            'created_at' => $day->copy()->addHours(10),
            'updated_at' => $day->copy()->addHours(10),
        ]);
        Order::query()->whereKey($orderB->id)->update([
            'created_at' => $day->copy()->addHours(11),
            'updated_at' => $day->copy()->addHours(11),
        ]);

        $service = app(DailySalesPushService::class);
        $service->processReferenceDate($day);
        $service->processReferenceDate($day);

        $this->assertSame(1, PanelPushDailySummaryLog::query()->where('tenant_id', $seller->id)->count());
        $this->assertSame(1, PanelPushDailySummaryLog::query()->where('tenant_id', $other->id)->count());
        $this->assertEquals(100, (float) PanelPushDailySummaryLog::query()->where('tenant_id', $seller->id)->value('orders_total'));
    }

    public function test_daily_summary_push_body_uses_today_count_and_platform_name(): void
    {
        if (! Schema::hasTable('panel_push_daily_summary_logs') || ! Schema::hasTable('orders')) {
            $this->markTestSkipped('tabelas necessárias');
        }

        DailySalesPushSettings::persist([
            'daily_sales_push_enabled' => true,
            'daily_sales_push_time' => '20:00',
            'daily_sales_push_timezone' => 'America/Sao_Paulo',
            'daily_sales_push_only_when_has_sales' => true,
        ]);

        $seller = $this->seller();
        $day = Carbon::now('America/Sao_Paulo')->startOfDay();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'checkout_slug' => 'dailysumtoday',
        ]);

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'payment_method' => 'pix',
        ]);
        Order::query()->whereKey($order->id)->update([
            'created_at' => $day->copy()->addHours(10),
            'updated_at' => $day->copy()->addHours(10),
        ]);

        $captured = [];
        $this->mock(PanelPushService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('sendAndPersistToTenant')
                ->once()
                ->andReturnUsing(function (...$args) use (&$captured) {
                    $captured = $args;

                    return 1;
                });
        });

        app(DailySalesPushService::class)->processReferenceDate($day);

        $this->assertNotEmpty($captured);
        $this->assertSame('daily_sales_summary', $captured[1]);
        $this->assertSame('Resumo de vendas do dia', $captured[2]);
        $this->assertMatchesRegularExpression(
            '/^Hoje você fez 1 venda, obrigado por usar a .+\.$/',
            (string) $captured[3]
        );
    }

    public function test_schedule_command_dispatches_daily_sales_after_configured_time(): void
    {
        Bus::fake([ProcessDailySalesPushJob::class]);
        Cache::flush();

        DailySalesPushSettings::persist([
            'daily_sales_push_enabled' => true,
            'daily_sales_push_time' => '20:00',
            'daily_sales_push_timezone' => 'America/Sao_Paulo',
            'daily_sales_push_only_when_has_sales' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-12 19:59:00', 'America/Sao_Paulo'));
        $this->artisan('push:process-schedule')->assertSuccessful();
        Bus::assertNotDispatched(ProcessDailySalesPushJob::class);

        Carbon::setTestNow(Carbon::parse('2026-08-12 20:03:00', 'America/Sao_Paulo'));
        $this->artisan('push:process-schedule')->assertSuccessful();
        $this->artisan('push:process-schedule')->assertSuccessful();
        Bus::assertDispatchedTimes(ProcessDailySalesPushJob::class, 1);

        Carbon::setTestNow();
    }

    public function test_send_now_is_not_blocked_by_timezone_and_uses_utc(): void
    {
        Bus::fake([ProcessPanelPushCampaignJob::class]);
        $this->configureTestVapidPush();
        config(['app.timezone' => 'America/Sao_Paulo']);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Agora',
                'body' => 'Envio imediato',
                'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
                'send_mode' => 'now',
                'timezone' => 'America/Sao_Paulo',
                'confirm_global' => true,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $campaign = PanelPushCampaign::query()->first();
        $this->assertNotNull($campaign);
        $this->assertSame(PanelPushCampaign::MODE_NOW, $campaign->send_mode);
        $this->assertNotNull($campaign->scheduled_at);
        $this->assertSame('UTC', $campaign->scheduled_at->timezoneName);
        $this->assertTrue($campaign->scheduled_at->between(now('UTC')->subMinute(), now('UTC')->addMinute()));

        Bus::assertDispatched(ProcessPanelPushCampaignJob::class);

        $service = app(PanelPushCampaignService::class);
        $service->process($campaign->id);
        $this->assertNotSame(PanelPushCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    public function test_scheduled_local_sao_paulo_is_stored_as_utc(): void
    {
        Bus::fake([ProcessPanelPushCampaignJob::class]);
        $this->configureTestVapidPush();
        // Simula install com APP_TIMEZONE ≠ UTC (comum) e SO em UTC.
        config(['app.timezone' => 'America/Sao_Paulo']);
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Agendada',
                'body' => 'Mensagem',
                'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
                'send_mode' => 'scheduled',
                'scheduled_local' => '2026-08-01T15:40',
                'timezone' => 'America/Sao_Paulo',
                'confirm_global' => true,
            ])
            ->assertOk();

        $campaign = PanelPushCampaign::query()->first();
        $this->assertNotNull($campaign);
        $this->assertSame('UTC', $campaign->scheduled_at?->timezoneName);
        // 15:40 America/Sao_Paulo = 18:40 UTC
        $this->assertSame('2026-08-01 18:40:00', $campaign->scheduled_at?->utc()->format('Y-m-d H:i:s'));
        $raw = \Illuminate\Support\Facades\DB::table('panel_push_campaigns')->where('id', $campaign->id)->value('scheduled_at');
        $this->assertStringStartsWith('2026-08-01 18:40:00', (string) $raw);
    }

    public function test_seller_cannot_access_admin_push_campaigns(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)
            ->getJson(route('plataforma.app.push.campaigns'))
            ->assertForbidden();
    }

    public function test_unsafe_url_rejected(): void
    {
        $this->configureTestVapidPush();
        $admin = $this->platformAdmin();
        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.send'), [
                'title' => 'Bad',
                'body' => 'Body',
                'url' => 'javascript:alert(1)',
                'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
                'send_mode' => 'now',
                'confirm_global' => true,
            ])
            ->assertStatus(422);
    }

    public function test_transient_db_failure_releases_campaign_for_retry(): void
    {
        Bus::fake([ProcessPanelPushCampaignJob::class]);
        $this->configureTestVapidPush();
        $admin = $this->platformAdmin();

        $campaign = PanelPushCampaign::query()->create([
            'title' => 'Retry',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_NOW,
            'scheduled_at' => now('UTC'),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_SCHEDULED,
            'idempotency_key' => 'retry-1',
            'created_by' => $admin->id,
        ]);

        $service = app(PanelPushCampaignService::class);

        $push = \Mockery::mock(\App\Services\PanelPushService::class)->makePartial();
        $push->shouldReceive('filterSubscriptionsForDelivery')->andReturn(collect());
        $push->shouldReceive('sendToSubscriptions')->andThrow(
            new \PDOException('SQLSTATE[08006] Connection refused while connecting to postgres:5433')
        );
        $this->app->instance(\App\Services\PanelPushService::class, $push);
        $service = app(PanelPushCampaignService::class);

        try {
            $service->process($campaign->id);
            $this->fail('Deveria ter lançado TransientInfrastructureException');
        } catch (\App\Exceptions\TransientInfrastructureException $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        $fresh = $campaign->fresh();
        $this->assertSame(PanelPushCampaign::STATUS_SCHEDULED, $fresh->status);
        $this->assertNotSame(PanelPushCampaign::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->last_error);
    }

    public function test_duplicate_process_is_idempotent_for_inbox(): void
    {
        Bus::fake([ProcessPanelPushCampaignJob::class]);
        $this->configureTestVapidPush();
        $admin = $this->platformAdmin();
        $seller = $this->seller();

        \App\Models\PanelPushSubscription::query()->create([
            'user_id' => $seller->id,
            'tenant_id' => $seller->id,
            'endpoint' => 'https://example.test/push/'.$seller->id,
            'keys' => ['p256dh' => 'x', 'auth' => 'y'],
            'provider' => \App\Models\PanelPushSubscription::PROVIDER_VAPID,
        ]);

        $campaign = PanelPushCampaign::query()->create([
            'title' => 'Dup',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_NOW,
            'scheduled_at' => now('UTC'),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_SCHEDULED,
            'idempotency_key' => 'dup-1',
            'created_by' => $admin->id,
        ]);

        $push = \Mockery::mock(\App\Services\PanelPushService::class)->makePartial();
        $push->shouldReceive('filterSubscriptionsForDelivery')->andReturnUsing(fn ($c) => $c);
        $push->shouldReceive('sendToSubscriptions')->andReturn([
            'sent' => 1, 'failed' => 0, 'invalid' => 0, 'expired' => 0, 'total' => 1,
        ]);
        $this->app->instance(\App\Services\PanelPushService::class, $push);

        $service = app(PanelPushCampaignService::class);
        $service->process($campaign->id);
        $this->assertSame(PanelPushCampaign::STATUS_SENT, $campaign->fresh()->status);

        // Segunda chamada não reprocessa (status !== scheduled).
        $service->process($campaign->id);
        $count = \App\Models\PanelNotification::query()
            ->where('event_key', 'campaign_'.$campaign->id.'_'.$seller->id)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_recover_stuck_processing_reeschedules(): void
    {
        $admin = $this->platformAdmin();
        $campaign = PanelPushCampaign::query()->create([
            'title' => 'Stuck',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_SCHEDULED,
            'scheduled_at' => now('UTC')->subMinutes(5),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_PROCESSING,
            'processing_started_at' => now()->subMinutes(20),
            'idempotency_key' => 'stuck-1',
            'created_by' => $admin->id,
        ]);

        $n = app(PanelPushCampaignService::class)->recoverStuckProcessing(10);
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame(PanelPushCampaign::STATUS_SCHEDULED, $campaign->fresh()->status);
    }

    public function test_clear_history_selected_and_all(): void
    {
        $admin = $this->platformAdmin();
        $a = PanelPushCampaign::query()->create([
            'title' => 'A',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_NOW,
            'scheduled_at' => now('UTC'),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_SENT,
            'idempotency_key' => 'hist-a',
            'created_by' => $admin->id,
        ]);
        $b = PanelPushCampaign::query()->create([
            'title' => 'B',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_NOW,
            'scheduled_at' => now('UTC'),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_FAILED,
            'idempotency_key' => 'hist-b',
            'created_by' => $admin->id,
        ]);
        $pending = PanelPushCampaign::query()->create([
            'title' => 'Pending',
            'body' => 'Body',
            'audience' => PanelPushCampaign::AUDIENCE_ALL_SUBSCRIBERS,
            'send_mode' => PanelPushCampaign::MODE_SCHEDULED,
            'scheduled_at' => now('UTC')->addHour(),
            'timezone' => 'America/Sao_Paulo',
            'status' => PanelPushCampaign::STATUS_SCHEDULED,
            'idempotency_key' => 'hist-p',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson(route('plataforma.app.push.campaigns.destroy', $a))
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $this->assertNull($a->fresh());

        $this->actingAs($admin)
            ->postJson(route('plataforma.app.push.campaigns.clear-history'), ['all' => true])
            ->assertOk();

        $this->assertNull($b->fresh());
        $this->assertNotNull($pending->fresh());
    }

    public function test_job_has_retries_and_unique(): void
    {
        $job = new ProcessPanelPushCampaignJob(99);
        $this->assertSame(5, $job->tries);
        $this->assertSame([15, 30, 60, 120, 180], $job->backoff);
        $this->assertSame('panel-push-campaign:99', $job->uniqueId());
    }
}
