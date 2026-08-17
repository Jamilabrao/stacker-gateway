<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SellerActivityLog;
use App\Models\User;
use App\Services\SellerActivityLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerActivityLogsController extends Controller
{
    public function index(Request $request): Response
    {
        $merchantId = $request->integer('merchant_id') ?: null;
        $group = trim((string) $request->query('group', ''));
        $action = trim((string) $request->query('action', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $allowedActions = array_keys(SellerActivityLogService::ACTIONS);
        if ($action !== '' && ! in_array($action, $allowedActions, true)) {
            $action = '';
        }
        if ($group !== '' && ! array_key_exists($group, SellerActivityLogService::GROUPS)) {
            $group = '';
        }

        $query = SellerActivityLog::query()
            ->with([
                'actor:id,name,email,role',
                'merchant:id,name,email,role',
            ])
            ->orderByDesc('id');

        if ($merchantId) {
            $merchant = User::query()
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->whereKey($merchantId)
                ->first(['id', 'tenant_id']);
            if ($merchant) {
                $tenantId = (int) ($merchant->tenant_id ?: $merchant->id);
                $query->where('tenant_id', $tenantId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($action !== '') {
            $query->where('action', $action);
        } elseif ($group !== '') {
            $query->where('action_group', $group);
        }

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $logs = $query->paginate($perPage)->withQueryString();
        $logs->through(fn (SellerActivityLog $log) => $this->mapLog($log));

        $merchants = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])
            ->values()
            ->all();

        return Inertia::render('Platform/SellerActivityLogs/Index', [
            'logs' => $logs,
            'merchants' => $merchants,
            'action_options' => SellerActivityLogService::actionOptions(),
            'group_options' => SellerActivityLogService::groupOptions(),
            'filters' => [
                'merchant_id' => $merchantId,
                'group' => $group !== '' ? $group : null,
                'action' => $action !== '' ? $action : null,
                'date_from' => $dateFrom !== '' ? $dateFrom : null,
                'date_to' => $dateTo !== '' ? $dateTo : null,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLog(SellerActivityLog $log): array
    {
        $actor = $log->actor;
        $merchant = $log->merchant;

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_group' => $log->action_group,
            'action_label' => SellerActivityLogService::ACTIONS[$log->action]['label'] ?? $log->action,
            'group_label' => SellerActivityLogService::GROUPS[$log->action_group] ?? $log->action_group,
            'source' => $log->source,
            'summary' => $log->summary,
            'metadata' => is_array($log->metadata) ? $log->metadata : [],
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601String(),
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'email' => $merchant->email,
            ] : [
                'id' => $log->tenant_id,
                'name' => 'Infoprodutor #'.$log->tenant_id,
                'email' => null,
            ],
            'actor' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'role' => $actor->role,
                'role_label' => $actor->isTeam() ? 'Equipe' : 'Titular',
            ] : null,
        ];
    }
}
