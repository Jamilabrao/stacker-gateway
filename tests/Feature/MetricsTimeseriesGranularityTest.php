<?php

namespace Tests\Feature;

use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use App\Services\MetricsTracking\MetricsAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetricsTimeseriesGranularityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hoje_timeseries_uses_24_hourly_buckets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 15:30:00', 'America/Sao_Paulo'));

        $tenantId = 42;
        $sessionKey = (string) Str::uuid();
        $visitorKey = (string) Str::uuid();

        MetricsSession::query()->create([
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => $tenantId,
            'first_touch_at' => now()->copy()->setTime(10, 15),
            'last_touch_at' => now()->copy()->setTime(14, 5),
        ]);

        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::PAGE_VIEW, now()->copy()->setTime(10, 15));
        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::PIX_CREATED, now()->copy()->setTime(10, 20));
        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::PAYMENT_APPROVED, now()->copy()->setTime(14, 5), 150.5);

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => 'hoje']);
        [$start, $end] = $service->resolveDateRange($request, 'hoje');
        $rows = $service->timeseries($tenantId, $start, $end, ['group_by' => 'day']);

        $this->assertCount(24, $rows);
        $this->assertSame('0h', $rows[0]['bucket']);
        $this->assertSame('23h', $rows[23]['bucket']);
        $this->assertSame(1, $rows[10]['clicks']);
        $this->assertSame(1, $rows[10]['pix_created']);
        $this->assertSame(1, $rows[10]['visitors']);
        $this->assertSame(1, $rows[14]['conversions']);
        $this->assertEquals(150.5, $rows[14]['revenue']);
        $this->assertSame(0, $rows[11]['clicks']);
    }

    public function test_ontem_timeseries_uses_24_hourly_buckets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 15:30:00', 'America/Sao_Paulo'));

        $tenantId = 43;
        $sessionKey = (string) Str::uuid();
        $visitorKey = (string) Str::uuid();
        $yesterday = now()->copy()->subDay()->setTime(8, 0);

        MetricsSession::query()->create([
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => $tenantId,
            'first_touch_at' => $yesterday,
            'last_touch_at' => $yesterday,
        ]);
        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::PAGE_VIEW, $yesterday);

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => 'ontem']);
        [$start, $end] = $service->resolveDateRange($request, 'ontem');
        $rows = $service->timeseries($tenantId, $start, $end, ['group_by' => 'day']);

        $this->assertCount(24, $rows);
        $this->assertSame('8h', $rows[8]['bucket']);
        $this->assertSame(1, $rows[8]['clicks']);
        $this->assertSame(1, $rows[8]['visitors']);
    }

    public function test_7dias_timeseries_stays_daily(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 15:30:00', 'America/Sao_Paulo'));

        $tenantId = 44;
        $firstKey = (string) Str::uuid();
        $secondKey = (string) Str::uuid();

        MetricsSession::query()->create([
            'session_key' => $firstKey,
            'visitor_key' => $firstKey,
            'tenant_id' => $tenantId,
            'first_touch_at' => now()->copy()->subDays(5)->setTime(11, 0),
            'last_touch_at' => now()->copy()->subDays(5)->setTime(11, 0),
        ]);
        MetricsSession::query()->create([
            'session_key' => $secondKey,
            'visitor_key' => $secondKey,
            'tenant_id' => $tenantId,
            'first_touch_at' => now()->copy()->subDays(1)->setTime(11, 0),
            'last_touch_at' => now()->copy()->subDays(1)->setTime(11, 0),
        ]);
        $this->insertEvent($tenantId, $firstKey, $firstKey, MetricsEvent::PAGE_VIEW, now()->copy()->subDays(5)->setTime(11, 0));
        $this->insertEvent($tenantId, $secondKey, $secondKey, MetricsEvent::PAGE_VIEW, now()->copy()->subDays(1)->setTime(11, 0));

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => '7dias']);
        [$start, $end] = $service->resolveDateRange($request, '7dias');
        $rows = $service->timeseries($tenantId, $start, $end, ['group_by' => 'day']);

        $this->assertCount(2, $rows);
        $this->assertSame(now()->copy()->subDays(5)->toDateString(), $rows[0]['bucket']);
        $this->assertSame(now()->copy()->subDays(1)->toDateString(), $rows[1]['bucket']);
        $this->assertStringEndsNotWith('h', $rows[0]['bucket']);
    }

    private function insertEvent(
        int $tenantId,
        string $sessionKey,
        string $visitorKey,
        string $eventName,
        Carbon $occurredAt,
        ?float $amount = null,
    ): void {
        MetricsEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_name' => $eventName,
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => $tenantId,
            'amount' => $amount,
            'occurred_at' => $occurredAt,
        ]);
    }
}
