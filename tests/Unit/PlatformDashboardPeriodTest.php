<?php

namespace Tests\Unit;

use App\Support\PlatformDashboardPeriod;
use Carbon\Carbon;
use Tests\TestCase;

class PlatformDashboardPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hoje_previous_range_is_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 15:30:00'));
        [$start, $end] = PlatformDashboardPeriod::range('hoje');
        [$prevStart, $prevEnd] = PlatformDashboardPeriod::previousRange($start, $end);

        $this->assertSame('2026-08-21 00:00:00', $start);
        $this->assertSame('2026-08-20 00:00:00', $prevStart);
        $this->assertSame('2026-08-20 23:59:59', $prevEnd);
    }

    public function test_total_has_no_previous_range(): void
    {
        [$start, $end] = PlatformDashboardPeriod::range('total');
        [$prevStart, $prevEnd] = PlatformDashboardPeriod::previousRange($start, $end);

        $this->assertNull($start);
        $this->assertNull($prevStart);
        $this->assertNull($prevEnd);
    }

    public function test_delta_percent_avoids_division_by_zero(): void
    {
        $this->assertSame(0.0, PlatformDashboardPeriod::deltaPercent(0, 0));
        $this->assertNull(PlatformDashboardPeriod::deltaPercent(10, 0));
        $this->assertSame(100.0, PlatformDashboardPeriod::deltaPercent(20, 10));
        $this->assertSame(-50.0, PlatformDashboardPeriod::deltaPercent(5, 10));
    }

    public function test_ano_granularity_is_month(): void
    {
        $this->assertSame('hour', PlatformDashboardPeriod::granularity('hoje'));
        $this->assertSame('day', PlatformDashboardPeriod::granularity('7dias'));
        $this->assertSame('month', PlatformDashboardPeriod::granularity('ano'));
        $this->assertSame('month', PlatformDashboardPeriod::granularity('total'));
    }
}
