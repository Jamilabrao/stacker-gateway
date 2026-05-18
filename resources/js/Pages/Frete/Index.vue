<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { useViaCep } from '@/composables/useViaCep';
import { Plus, Pencil, Trash2, Truck, MapPin, GripVertical } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    stores: { type: Array, default: () => [] },
    match_types: { type: Array, default: () => [] },
    brazil_states: { type: Array, default: () => [] },
});

const storesLocal = ref([...props.stores]);
const selectedStoreId = ref(storesLocal.value[0]?.id ?? null);
const rules = ref([]);
const rulesLoading = ref(false);
const storeModalOpen = ref(false);
const ruleModalOpen = ref(false);
const editingStore = ref(null);
const editingRule = ref(null);

const storeForm = ref({
    name: '',
    is_active: true,
    origin_zip: '',
    origin_street: '',
    origin_number: '',
    origin_complement: '',
    origin_neighborhood: '',
    origin_city: '',
    origin_state: '',
});

const ruleForm = ref({
    name: '',
    priority: 100,
    is_active: true,
    match_type: 'all',
    match_config: {},
    price: 0,
    is_free: false,
    delivery_days_min: null,
    delivery_days_max: null,
});

const selectedStates = ref([]);
const cityItems = ref([{ uf: 'SP', city: '' }]);
const cepRange = ref({ from: '', to: '' });
const cepPrefixes = ref('');

const { fetchCep, loading: cepLoading, error: cepError } = useViaCep();

const selectedStore = computed(() => storesLocal.value.find((s) => s.id === selectedStoreId.value) ?? null);

function getCsrfToken() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function loadRules() {
    if (!selectedStoreId.value) {
        rules.value = [];
        return;
    }
    rulesLoading.value = true;
    try {
        const { data } = await axios.get(`/frete/lojas/${selectedStoreId.value}/regras`, {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });
        rules.value = data.rules ?? [];
    } finally {
        rulesLoading.value = false;
    }
}

watch(selectedStoreId, () => loadRules(), { immediate: true });

function openStoreModal(store = null) {
    editingStore.value = store;
    if (store) {
        storeForm.value = { ...store, is_active: !!store.is_active };
    } else {
        storeForm.value = {
            name: '',
            is_active: true,
            origin_zip: '',
            origin_street: '',
            origin_number: '',
            origin_complement: '',
            origin_neighborhood: '',
            origin_city: '',
            origin_state: '',
        };
    }
    storeModalOpen.value = true;
}

async function onStoreCepBlur() {
    const data = await fetchCep(storeForm.value.origin_zip);
    if (!data) return;
    storeForm.value.origin_street = data.street || storeForm.value.origin_street;
    storeForm.value.origin_neighborhood = data.neighborhood || storeForm.value.origin_neighborhood;
    storeForm.value.origin_city = data.city || storeForm.value.origin_city;
    storeForm.value.origin_state = data.uf || storeForm.value.origin_state;
}

async function saveStore() {
    const payload = { ...storeForm.value, is_active: !!storeForm.value.is_active };
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() };
    if (editingStore.value?.id) {
        const { data } = await axios.put(`/frete/lojas/${editingStore.value.id}`, payload, { headers });
        const idx = storesLocal.value.findIndex((s) => s.id === editingStore.value.id);
        if (idx >= 0) storesLocal.value[idx] = data.store;
    } else {
        const { data } = await axios.post('/frete/lojas', payload, { headers });
        storesLocal.value.push(data.store);
        selectedStoreId.value = data.store.id;
    }
    storeModalOpen.value = false;
}

async function deleteStore(store) {
    if (!confirm(`Excluir a loja "${store.name}"?`)) return;
    await axios.delete(`/frete/lojas/${store.id}`, {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    });
    storesLocal.value = storesLocal.value.filter((s) => s.id !== store.id);
    if (selectedStoreId.value === store.id) {
        selectedStoreId.value = storesLocal.value[0]?.id ?? null;
    }
}

function syncRuleFormFromMatch() {
    const t = ruleForm.value.match_type;
    if (t === 'state') {
        ruleForm.value.match_config = { states: [...selectedStates.value] };
    } else if (t === 'city') {
        ruleForm.value.match_config = { items: cityItems.value.filter((i) => i.city?.trim()) };
    } else if (t === 'cep_range') {
        ruleForm.value.match_config = { from: cepRange.value.from, to: cepRange.value.to };
    } else if (t === 'cep_prefix') {
        const prefixes = cepPrefixes.value.split(/[\s,;]+/).map((p) => p.trim()).filter(Boolean);
        ruleForm.value.match_config = { prefixes };
    } else {
        ruleForm.value.match_config = {};
    }
}

function loadMatchEditorsFromConfig(config, matchType) {
    selectedStates.value = config?.states ?? [];
    cityItems.value = config?.items?.length ? [...config.items] : [{ uf: 'SP', city: '' }];
    cepRange.value = { from: config?.from ?? '', to: config?.to ?? '' };
    cepPrefixes.value = (config?.prefixes ?? []).join(', ');
}

function openRuleModal(rule = null) {
    editingRule.value = rule;
    if (rule) {
        ruleForm.value = {
            name: rule.name ?? '',
            priority: rule.priority ?? 100,
            is_active: !!rule.is_active,
            match_type: rule.match_type,
            match_config: rule.match_config ?? {},
            price: rule.price ?? 0,
            is_free: !!rule.is_free,
            delivery_days_min: rule.delivery_days_min,
            delivery_days_max: rule.delivery_days_max,
        };
        loadMatchEditorsFromConfig(rule.match_config, rule.match_type);
    } else {
        ruleForm.value = {
            name: '',
            priority: (rules.value.length + 1) * 10,
            is_active: true,
            match_type: 'all',
            match_config: {},
            price: 0,
            is_free: false,
            delivery_days_min: null,
            delivery_days_max: null,
        };
        loadMatchEditorsFromConfig({}, 'all');
    }
    ruleModalOpen.value = true;
}

async function saveRule() {
    syncRuleFormFromMatch();
    const payload = {
        ...ruleForm.value,
        is_active: !!ruleForm.value.is_active,
        is_free: !!ruleForm.value.is_free,
        price: ruleForm.value.is_free ? 0 : Number(ruleForm.value.price) || 0,
    };
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() };
    const storeId = selectedStoreId.value;
    if (editingRule.value?.id) {
        const { data } = await axios.put(`/frete/lojas/${storeId}/regras/${editingRule.value.id}`, payload, { headers });
        const idx = rules.value.findIndex((r) => r.id === editingRule.value.id);
        if (idx >= 0) rules.value[idx] = data.rule;
    } else {
        const { data } = await axios.post(`/frete/lojas/${storeId}/regras`, payload, { headers });
        rules.value.push(data.rule);
    }
    ruleModalOpen.value = false;
}

async function deleteRule(rule) {
    if (!confirm('Excluir esta regra de frete?')) return;
    await axios.delete(`/frete/lojas/${selectedStoreId.value}/regras/${rule.id}`, {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
    });
    rules.value = rules.value.filter((r) => r.id !== rule.id);
}

function matchTypeLabel(type) {
    return props.match_types.find((m) => m.id === type)?.label ?? type;
}

function formatPrice(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Taxas e frete</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Cadastre lojas (centros de expedição) e regras de frete por região, cidade ou CEP.
                </p>
            </div>
            <Button type="button" @click="openStoreModal()">
                <Plus class="mr-2 h-4 w-4" />
                Nova loja
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-2 lg:col-span-1">
                <h2 class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Lojas</h2>
                <div
                    v-if="storesLocal.length === 0"
                    class="rounded-xl border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-600"
                >
                    Nenhuma loja cadastrada.
                </div>
                <button
                    v-for="store in storesLocal"
                    :key="store.id"
                    type="button"
                    :class="[
                        'w-full rounded-xl border p-4 text-left transition',
                        selectedStoreId === store.id
                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5'
                            : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800',
                    ]"
                    @click="selectedStoreId = store.id"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ store.name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ store.origin_summary }}</p>
                            <p class="mt-1 text-xs text-zinc-400">
                                {{ store.active_rules_count ?? 0 }} regra(s) ativa(s)
                            </p>
                        </div>
                        <span
                            :class="store.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-600'"
                            class="shrink-0 rounded px-1.5 py-0.5 text-xs"
                        >
                            {{ store.is_active ? 'Ativa' : 'Inativa' }}
                        </span>
                    </div>
                </button>
            </div>

            <div class="lg:col-span-2">
                <div v-if="!selectedStore" class="rounded-xl border border-zinc-200 p-8 text-center text-zinc-500 dark:border-zinc-700">
                    Selecione ou crie uma loja.
                </div>
                <div v-else class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <div>
                            <h2 class="font-semibold text-zinc-900 dark:text-white">{{ selectedStore.name }}</h2>
                            <p class="text-xs text-zinc-500">Regras avaliadas por prioridade (menor número = primeiro)</p>
                        </div>
                        <div class="flex gap-2">
                            <Button type="button" variant="outline" size="sm" @click="openStoreModal(selectedStore)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="outline" size="sm" @click="deleteStore(selectedStore)">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                            <Button type="button" size="sm" @click="openRuleModal()">
                                <Plus class="mr-1 h-4 w-4" />
                                Regra
                            </Button>
                        </div>
                    </div>
                    <div v-if="rulesLoading" class="p-6 text-sm text-zinc-500">Carregando regras…</div>
                    <div v-else-if="rules.length === 0" class="p-6 text-sm text-zinc-500">
                        Nenhuma regra. Adicione ao menos uma (ex.: Todo o Brasil com valor fixo).
                    </div>
                    <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        <li
                            v-for="rule in rules"
                            :key="rule.id"
                            class="flex items-center gap-3 px-4 py-3"
                        >
                            <GripVertical class="h-4 w-4 shrink-0 text-zinc-300" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ rule.name || matchTypeLabel(rule.match_type) }}
                                    <span class="text-xs font-normal text-zinc-400">#{{ rule.priority }}</span>
                                </p>
                                <p class="text-sm text-zinc-500">
                                    {{ matchTypeLabel(rule.match_type) }} —
                                    {{ rule.is_free ? 'Grátis' : formatPrice(rule.price) }}
                                    <span v-if="rule.delivery_days_min != null">
                                        · {{ rule.delivery_days_min }}–{{ rule.delivery_days_max ?? rule.delivery_days_min }} dias
                                    </span>
                                </p>
                            </div>
                            <Button type="button" variant="ghost" size="sm" @click="openRuleModal(rule)">
                                <Pencil class="h-4 w-4" />
                            </Button>
                            <Button type="button" variant="ghost" size="sm" @click="deleteRule(rule)">
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Modal loja -->
        <div v-if="storeModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="storeModalOpen = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">{{ editingStore ? 'Editar loja' : 'Nova loja' }}</h3>
                <form class="mt-4 space-y-3" @submit.prevent="saveStore">
                    <div>
                        <label class="text-sm font-medium">Nome da loja</label>
                        <input v-model="storeForm.name" required class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="storeForm.is_active" type="checkbox" />
                        Loja ativa
                    </label>
                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Endereço de origem (expedição)</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="text-xs">CEP</label>
                            <input v-model="storeForm.origin_zip" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" @blur="onStoreCepBlur" />
                            <p v-if="cepLoading" class="text-xs text-zinc-500">Buscando…</p>
                            <p v-else-if="cepError" class="text-xs text-red-600">{{ cepError }}</p>
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs">Rua</label>
                            <input v-model="storeForm.origin_street" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <div>
                            <label class="text-xs">Número</label>
                            <input v-model="storeForm.origin_number" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <div>
                            <label class="text-xs">Complemento</label>
                            <input v-model="storeForm.origin_complement" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <div class="col-span-2">
                            <label class="text-xs">Bairro</label>
                            <input v-model="storeForm.origin_neighborhood" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <div>
                            <label class="text-xs">Cidade</label>
                            <input v-model="storeForm.origin_city" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <div>
                            <label class="text-xs">UF</label>
                            <input v-model="storeForm.origin_state" maxlength="2" class="mt-1 w-full rounded-lg border px-3 py-2 uppercase dark:bg-zinc-800" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="storeModalOpen = false">Cancelar</Button>
                        <Button type="submit">Salvar</Button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal regra -->
        <div v-if="ruleModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="ruleModalOpen = false">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">{{ editingRule ? 'Editar regra' : 'Nova regra' }}</h3>
                <form class="mt-4 space-y-3" @submit.prevent="saveRule">
                    <input v-model="ruleForm.name" placeholder="Nome (opcional)" class="w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-xs">Prioridade</label>
                            <input v-model.number="ruleForm.priority" type="number" min="0" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                        </div>
                        <label class="flex items-end gap-2 pb-2 text-sm">
                            <input v-model="ruleForm.is_active" type="checkbox" />
                            Ativa
                        </label>
                    </div>
                    <div>
                        <label class="text-sm font-medium">Tipo de cobertura</label>
                        <select v-model="ruleForm.match_type" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800">
                            <option v-for="mt in match_types" :key="mt.id" :value="mt.id">{{ mt.label }}</option>
                        </select>
                    </div>
                    <div v-if="ruleForm.match_type === 'state'" class="space-y-2">
                        <label class="text-sm">Estados (UF)</label>
                        <div class="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                            <label v-for="st in brazil_states" :key="st.uf" class="flex items-center gap-1 text-xs">
                                <input v-model="selectedStates" type="checkbox" :value="st.uf" />
                                {{ st.uf }}
                            </label>
                        </div>
                    </div>
                    <div v-else-if="ruleForm.match_type === 'city'" class="space-y-2">
                        <div v-for="(item, idx) in cityItems" :key="idx" class="flex gap-2">
                            <select v-model="item.uf" class="w-20 rounded border px-2 py-1 dark:bg-zinc-800">
                                <option v-for="st in brazil_states" :key="st.uf" :value="st.uf">{{ st.uf }}</option>
                            </select>
                            <input v-model="item.city" placeholder="Cidade" class="flex-1 rounded border px-2 py-1 dark:bg-zinc-800" />
                        </div>
                        <Button type="button" variant="outline" size="sm" @click="cityItems.push({ uf: 'SP', city: '' })">+ Cidade</Button>
                    </div>
                    <div v-else-if="ruleForm.match_type === 'cep_range'" class="grid grid-cols-2 gap-2">
                        <input v-model="cepRange.from" placeholder="CEP de" class="rounded border px-3 py-2 dark:bg-zinc-800" />
                        <input v-model="cepRange.to" placeholder="CEP até" class="rounded border px-3 py-2 dark:bg-zinc-800" />
                    </div>
                    <div v-else-if="ruleForm.match_type === 'cep_prefix'">
                        <input v-model="cepPrefixes" placeholder="Prefixos separados por vírgula (ex: 01, 02)" class="w-full rounded border px-3 py-2 dark:bg-zinc-800" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="ruleForm.is_free" type="checkbox" />
                        Frete grátis nesta regra
                    </label>
                    <div v-if="!ruleForm.is_free">
                        <label class="text-sm">Valor (R$)</label>
                        <input v-model.number="ruleForm.price" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border px-3 py-2 dark:bg-zinc-800" />
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model.number="ruleForm.delivery_days_min" type="number" min="0" placeholder="Prazo mín. (dias)" class="rounded border px-3 py-2 dark:bg-zinc-800" />
                        <input v-model.number="ruleForm.delivery_days_max" type="number" min="0" placeholder="Prazo máx. (dias)" class="rounded border px-3 py-2 dark:bg-zinc-800" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" @click="ruleModalOpen = false">Cancelar</Button>
                        <Button type="submit">Salvar regra</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

