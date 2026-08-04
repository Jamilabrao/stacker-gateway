<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\UtmifyMetricsHealthService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UtmifyMetricsHealthController extends Controller
{
    public function __construct(
        private readonly UtmifyMetricsHealthService $health,
    ) {}

    public function index(Request $request): Response
    {
        $days = max(1, min(90, (int) $request->query('days', 7)));
        $sellerId = $request->integer('seller_id') ?: null;

        return Inertia::render('Platform/Ops/UtmifyMetricsHealth', [
            'dashboard' => $this->health->buildDashboard($days, $sellerId),
            'days' => $days,
            'seller_id' => $sellerId,
        ]);
    }
}
