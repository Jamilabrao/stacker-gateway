<?php

namespace App\Http\Controllers;

use App\Models\ShippingRule;
use App\Models\ShippingStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShippingController extends Controller
{
    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $stores = ShippingStore::forTenant($tenantId)
            ->withCount(['rules as active_rules_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (ShippingStore $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'origin_summary' => $s->originSummary(),
                'origin_zip' => $s->origin_zip,
                'origin_street' => $s->origin_street,
                'origin_number' => $s->origin_number,
                'origin_complement' => $s->origin_complement,
                'origin_neighborhood' => $s->origin_neighborhood,
                'origin_city' => $s->origin_city,
                'origin_state' => $s->origin_state,
                'active_rules_count' => $s->active_rules_count,
            ]);

        return Inertia::render('Frete/Index', [
            'stores' => $stores,
            'match_types' => $this->matchTypeOptions(),
            'brazil_states' => $this->brazilStates(),
        ]);
    }

    public function storeStore(Request $request): JsonResponse
    {
        $validated = $this->validateStore($request);
        $validated['tenant_id'] = auth()->user()->tenant_id;

        $store = ShippingStore::create($validated);

        return response()->json(['success' => true, 'store' => $this->storePayload($store)]);
    }

    public function updateStore(Request $request, ShippingStore $store): JsonResponse
    {
        $this->authorizeStore($store);
        $store->update($this->validateStore($request));

        return response()->json(['success' => true, 'store' => $this->storePayload($store->fresh())]);
    }

    public function destroyStore(ShippingStore $store): JsonResponse
    {
        $this->authorizeStore($store);
        $store->delete();

        return response()->json(['success' => true]);
    }

    public function storeRule(Request $request, ShippingStore $store): JsonResponse
    {
        $this->authorizeStore($store);
        $validated = $this->validateRule($request);
        $validated['shipping_store_id'] = $store->id;
        $rule = ShippingRule::create($validated);

        return response()->json(['success' => true, 'rule' => $this->rulePayload($rule)]);
    }

    public function updateRule(Request $request, ShippingStore $store, ShippingRule $rule): JsonResponse
    {
        $this->authorizeStore($store);
        $this->authorizeRule($store, $rule);
        $rule->update($this->validateRule($request));

        return response()->json(['success' => true, 'rule' => $this->rulePayload($rule->fresh())]);
    }

    public function destroyRule(ShippingStore $store, ShippingRule $rule): JsonResponse
    {
        $this->authorizeStore($store);
        $this->authorizeRule($store, $rule);
        $rule->delete();

        return response()->json(['success' => true]);
    }

    public function rules(ShippingStore $store): JsonResponse
    {
        $this->authorizeStore($store);
        $rules = $store->rules()->orderBy('priority')->orderBy('id')->get()
            ->map(fn (ShippingRule $r) => $this->rulePayload($r));

        return response()->json(['rules' => $rules]);
    }

    public function reorderRules(Request $request, ShippingStore $store): JsonResponse
    {
        $this->authorizeStore($store);
        $validated = $request->validate([
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer', 'exists:shipping_rules,id'],
        ]);
        $priority = 10;
        foreach ($validated['rule_ids'] as $ruleId) {
            ShippingRule::query()
                ->where('shipping_store_id', $store->id)
                ->where('id', $ruleId)
                ->update(['priority' => $priority]);
            $priority += 10;
        }

        return response()->json(['success' => true]);
    }

    private function authorizeStore(ShippingStore $store): void
    {
        if ((int) $store->tenant_id !== (int) auth()->user()->tenant_id) {
            abort(403);
        }
    }

    private function authorizeRule(ShippingStore $store, ShippingRule $rule): void
    {
        if ((int) $rule->shipping_store_id !== (int) $store->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStore(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['boolean'],
            'origin_zip' => ['nullable', 'string', 'max:9'],
            'origin_street' => ['nullable', 'string', 'max:255'],
            'origin_number' => ['nullable', 'string', 'max:32'],
            'origin_complement' => ['nullable', 'string', 'max:120'],
            'origin_neighborhood' => ['nullable', 'string', 'max:120'],
            'origin_city' => ['nullable', 'string', 'max:120'],
            'origin_state' => ['nullable', 'string', 'size:2'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'match_type' => ['required', 'string', 'in:'.implode(',', ShippingRule::matchTypes())],
            'match_config' => ['nullable', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_free' => ['boolean'],
            'delivery_days_min' => ['nullable', 'integer', 'min:0', 'max:365'],
            'delivery_days_max' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $validated['priority'] = $validated['priority'] ?? 100;
        $validated['is_free'] = $request->boolean('is_free');
        if ($validated['is_free']) {
            $validated['price'] = 0;
        } else {
            $validated['price'] = $validated['price'] ?? 0;
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(ShippingStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'is_active' => $store->is_active,
            'origin_summary' => $store->originSummary(),
            'origin_zip' => $store->origin_zip,
            'origin_street' => $store->origin_street,
            'origin_number' => $store->origin_number,
            'origin_complement' => $store->origin_complement,
            'origin_neighborhood' => $store->origin_neighborhood,
            'origin_city' => $store->origin_city,
            'origin_state' => $store->origin_state,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rulePayload(ShippingRule $rule): array
    {
        return [
            'id' => $rule->id,
            'shipping_store_id' => $rule->shipping_store_id,
            'name' => $rule->name,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
            'match_type' => $rule->match_type,
            'match_config' => $rule->match_config ?? [],
            'price' => (float) $rule->price,
            'is_free' => $rule->is_free,
            'delivery_days_min' => $rule->delivery_days_min,
            'delivery_days_max' => $rule->delivery_days_max,
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function matchTypeOptions(): array
    {
        return [
            ['id' => ShippingRule::MATCH_ALL, 'label' => 'Todo o Brasil'],
            ['id' => ShippingRule::MATCH_STATE, 'label' => 'Por estado (UF)'],
            ['id' => ShippingRule::MATCH_CITY, 'label' => 'Por cidade'],
            ['id' => ShippingRule::MATCH_CEP_RANGE, 'label' => 'Faixa de CEP'],
            ['id' => ShippingRule::MATCH_CEP_PREFIX, 'label' => 'Prefixo de CEP'],
        ];
    }

    /**
     * @return list<array{uf: string, name: string}>
     */
    private function brazilStates(): array
    {
        return [
            ['uf' => 'AC', 'name' => 'Acre'], ['uf' => 'AL', 'name' => 'Alagoas'], ['uf' => 'AP', 'name' => 'Amapá'],
            ['uf' => 'AM', 'name' => 'Amazonas'], ['uf' => 'BA', 'name' => 'Bahia'], ['uf' => 'CE', 'name' => 'Ceará'],
            ['uf' => 'DF', 'name' => 'Distrito Federal'], ['uf' => 'ES', 'name' => 'Espírito Santo'], ['uf' => 'GO', 'name' => 'Goiás'],
            ['uf' => 'MA', 'name' => 'Maranhão'], ['uf' => 'MT', 'name' => 'Mato Grosso'], ['uf' => 'MS', 'name' => 'Mato Grosso do Sul'],
            ['uf' => 'MG', 'name' => 'Minas Gerais'], ['uf' => 'PA', 'name' => 'Pará'], ['uf' => 'PB', 'name' => 'Paraíba'],
            ['uf' => 'PR', 'name' => 'Paraná'], ['uf' => 'PE', 'name' => 'Pernambuco'], ['uf' => 'PI', 'name' => 'Piauí'],
            ['uf' => 'RJ', 'name' => 'Rio de Janeiro'], ['uf' => 'RN', 'name' => 'Rio Grande do Norte'],
            ['uf' => 'RS', 'name' => 'Rio Grande do Sul'], ['uf' => 'RO', 'name' => 'Rondônia'], ['uf' => 'RR', 'name' => 'Roraima'],
            ['uf' => 'SC', 'name' => 'Santa Catarina'], ['uf' => 'SP', 'name' => 'São Paulo'], ['uf' => 'SE', 'name' => 'Sergipe'],
            ['uf' => 'TO', 'name' => 'Tocantins'],
        ];
    }
}
