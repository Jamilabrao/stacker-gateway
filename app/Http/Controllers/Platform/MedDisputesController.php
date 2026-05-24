<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MedDispute;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedDisputesController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');
        $tenantId = $request->query('tenant_id');

        $q = MedDispute::query()
            ->with(['order.product', 'tenantOwner'])
            ->orderByDesc('id');

        if ($status === 'open') {
            $q->open();
        } elseif ($status === 'resolved') {
            $q->whereNotIn('status', [MedDispute::STATUS_OPEN, MedDispute::STATUS_DEFENSE_SUBMITTED]);
        }

        if ($tenantId !== null && $tenantId !== '') {
            $q->where('tenant_id', (int) $tenantId);
        }

        $rows = $q->paginate(30)->withQueryString();

        return Inertia::render('Platform/MedDisputes/Index', [
            'disputes' => $rows->through(fn (MedDispute $d) => $this->serialize($d)),
            'filters' => [
                'status' => $status,
                'tenant_id' => $tenantId,
            ],
            'pageTitle' => 'Disputas MED',
        ]);
    }

    public function show(MedDispute $dispute): Response
    {
        $dispute->load(['order.product', 'order.user', 'tenantOwner']);

        return Inertia::render('Platform/MedDisputes/Show', [
            'dispute' => $this->serialize($dispute, true),
            'pageTitle' => 'Disputa MED #'.$dispute->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MedDispute $dispute, bool $detail = false): array
    {
        $order = $dispute->order;
        $owner = $dispute->tenantOwner;

        $data = [
            'id' => $dispute->id,
            'cajupay_dispute_id' => $dispute->cajupay_dispute_id,
            'cajupay_payment_id' => $dispute->cajupay_payment_id,
            'status' => $dispute->status,
            'outcome' => $dispute->outcome,
            'amount_cents' => $dispute->amount_cents,
            'txid' => $dispute->txid,
            'defense_text' => $dispute->defense_text,
            'defended_at' => $dispute->defended_at?->toIso8601String(),
            'opened_at' => $dispute->opened_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'tenant' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'public_reference' => $order->public_reference,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'gateway' => $order->gateway,
            ] : null,
        ];

        if ($detail && $order) {
            $data['order']['email'] = $order->email;
            $data['order']['product_name'] = $order->product?->name;
        }

        return $data;
    }
}
