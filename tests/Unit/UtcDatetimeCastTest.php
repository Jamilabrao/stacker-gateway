<?php

namespace Tests\Unit;

use App\Casts\UtcDatetime;
use App\Models\PanelPushCampaign;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class UtcDatetimeCastTest extends TestCase
{
    public function test_set_converts_sao_paulo_instant_to_utc_wall_clock(): void
    {
        $cast = new UtcDatetime;
        $model = new PanelPushCampaign;
        $local = Carbon::parse('2026-08-01 15:40:00', 'America/Sao_Paulo');

        $stored = $cast->set($model, 'scheduled_at', $local, []);

        $this->assertSame('2026-08-01 18:40:00', $stored);
    }

    public function test_get_reads_naive_value_as_utc(): void
    {
        $cast = new UtcDatetime;
        $model = new PanelPushCampaign;

        $value = $cast->get($model, 'scheduled_at', '2026-08-01 18:40:00', []);

        $this->assertNotNull($value);
        $this->assertSame('UTC', $value->timezoneName);
        $this->assertSame('2026-08-01 15:40:00', $value->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }
}
