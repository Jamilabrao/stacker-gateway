<?php

namespace App\Http\Controllers\Platform;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\InboundGatewayWebhook;
use App\Support\PlatformTransactionsListing;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class InboundWebhooksController extends Controller
{
    public function index(Request $request): Response
    {
        $gateway = trim((string) $request->query('gateway', ''));
        $q = trim((string) $request->query('q', ''));
        $perPage = PlatformTransactionsListing::normalizePerPage($request->query('per_page'));

        $acquirers = $this->acquirerOptions();
        $allowedSlugs = array_column($acquirers, 'slug');
        if ($gateway !== '' && ! in_array($gateway, $allowedSlugs, true)) {
            $gateway = '';
        }

        if (! Schema::hasTable('inbound_gateway_webhooks')) {
            $empty = new LengthAwarePaginator([], 0, $perPage, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);

            return Inertia::render('Platform/Webhooks/Index', [
                'webhooks' => $empty,
                'acquirers' => $acquirers,
                'filters' => [
                    'gateway' => $gateway !== '' ? $gateway : null,
                    'q' => $q !== '' ? $q : null,
                    'per_page' => $perPage,
                ],
            ]);
        }

        $query = InboundGatewayWebhook::query()->orderByDesc('id');
        if ($gateway !== '') {
            $query->where('gateway_slug', $gateway);
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('event', 'like', $like)
                    ->orWhere('transaction_id', 'like', $like)
                    ->orWhere('path', 'like', $like);
            });
        }

        $webhooks = $query->paginate($perPage)->withQueryString();
        $webhooks->through(fn (InboundGatewayWebhook $row) => $this->mapRow($row));

        return Inertia::render('Platform/Webhooks/Index', [
            'webhooks' => $webhooks,
            'acquirers' => $acquirers,
            'filters' => [
                'gateway' => $gateway !== '' ? $gateway : null,
                'q' => $q !== '' ? $q : null,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    private function acquirerOptions(): array
    {
        $bySlug = [];
        foreach (GatewayRegistry::all() as $def) {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $bySlug[$slug] = [
                'slug' => $slug,
                'name' => (string) ($def['name'] ?? $slug),
            ];
        }

        if (Schema::hasTable('inbound_gateway_webhooks')) {
            $stored = InboundGatewayWebhook::query()
                ->distinct()
                ->orderBy('gateway_slug')
                ->pluck('gateway_slug');
            foreach ($stored as $slug) {
                if (! is_string($slug) || $slug === '' || isset($bySlug[$slug])) {
                    continue;
                }
                $bySlug[$slug] = [
                    'slug' => $slug,
                    'name' => $slug,
                ];
            }
        }

        $rows = array_values($bySlug);
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(InboundGatewayWebhook $row): array
    {
        $def = GatewayRegistry::get((string) $row->gateway_slug);

        return [
            'id' => $row->id,
            'gateway_slug' => $row->gateway_slug,
            'gateway_name' => is_array($def) ? (string) ($def['name'] ?? $row->gateway_slug) : $row->gateway_slug,
            'http_method' => $row->http_method,
            'path' => $row->path,
            'event' => $row->event,
            'transaction_id' => $row->transaction_id,
            'http_status' => $row->http_status,
            'payload' => is_array($row->payload) ? $row->payload : null,
            'headers' => is_array($row->headers) ? $row->headers : null,
            'ip' => $row->ip,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
