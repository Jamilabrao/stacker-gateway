<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { htmlToText } from '@/lib/sanitizeHtml';
import { ScrollText, ChevronDown, ChevronUp } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    logs: { type: Object, required: true },
    merchants: { type: Array, default: () => [] },
    action_options: { type: Array, default: () => [] },
    group_options: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({
            merchant_id: null,
            group: null,
            action: null,
            date_from: null,
            date_to: null,
            per_page: 25,
        }),
    },
});

const merchantId = ref(props.filters.merchant_id ? String(props.filters.merchant_id) : '');
const group = ref(props.filters.group || '');
const action = ref(props.filters.action || '');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const perPage = ref(String(props.filters.per_page || 25));
const expandedId = ref(null);

watch(
    () => props.filters,
    (f) => {
        merchantId.value = f.merchant_id ? String(f.merchant_id) : '';
        group.value = f.group || '';
        action.value = f.action || '';
        dateFrom.value = f.date_from || '';
        dateTo.value = f.date_to || '';
        perPage.value = String(f.per_page || 25);
    }
);

const rows = computed(() => (Array.isArray(props.logs?.data) ? props.logs.data : []));
const paginationLinks = computed(() => (Array.isArray(props.logs?.links) ? props.logs.links : []));

const filteredActionOptions = computed(() => {
    if (!group.value) {
        return props.action_options;
    }
    return props.action_options.filter((opt) => opt.group === group.value);
});

function listingQuery(overrides = {}) {
    const nextGroup = overrides.group !== undefined ? overrides.group : group.value;
    const nextAction = overrides.action !== undefined ? overrides.action : action.value;
    const query = {
        merchant_id: (overrides.merchant_id !== undefined ? overrides.merchant_id : merchantId.value) || undefined,
        group: nextGroup || undefined,
        action: nextAction || undefined,
        date_from: (overrides.date_from !== undefined ? overrides.date_from : dateFrom.value) || undefined,
        date_to: (overrides.date_to !== undefined ? overrides.date_to : dateTo.value) || undefined,
        per_page: Number(overrides.per_page !== undefined ? overrides.per_page : perPage.value) || 25,
    };

    if (overrides.page) {
        query.page = Number(overrides.page);
    }

    return query;
}

function applyFilters() {
    router.get('/plataforma/log-infoprodutor', listingQuery({ page: 1 }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    merchantId.value = '';
    group.value = '';
    action.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    perPage.value = '25';
    router.get('/plataforma/log-infoprodutor', {}, { preserveState: true, replace: true });
}

function selectGroup(nextGroup) {
    group.value = nextGroup;
    if (action.value) {
        const stillValid = props.action_options.some(
            (opt) => opt.value === action.value && (!nextGroup || opt.group === nextGroup)
        );
        if (!stillValid) {
            action.value = '';
        }
    }
    applyFilters();
}

function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('pt-BR');
}

function sourceLabel(source) {
    if (source === 'api') return 'API';
    if (source === 'job' || source === 'system') return 'Sistema';
    return 'Painel';
}

function isFailureLog(log) {
    return typeof log?.action === 'string' && (log.action.endsWith('.failed') || log.metadata?.outcome === 'failed');
}

const META_LABELS = {
    reason: 'Motivo',
    gateway_status: 'Status na adquirente',
    error_code: 'Código de erro',
    gateway: 'Adquirente',
    gateway_note: 'Nota da adquirente',
    order_id: 'Pedido',
    amount: 'Valor',
    failure_kind: 'Tipo da falha',
    acquirer_status: 'Status retornado',
    poll_attempts: 'Consultas à adquirente',
    outcome: 'Resultado',
    refund_request_id: 'Solicitação',
};

function metaLabel(key) {
    return META_LABELS[key] || key;
}

function metadataEntries(meta) {
    if (!meta || typeof meta !== 'object') return [];
    return Object.entries(meta).filter(([, v]) => v !== null && v !== undefined && v !== '');
}

function formatMetaValue(value) {
    if (typeof value === 'boolean') return value ? 'sim' : 'não';
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

function toggleExpanded(id) {
    expandedId.value = expandedId.value === id ? null : id;
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                <ScrollText class="h-6 w-6 text-[var(--color-primary)]" />
                Log Infoprodutor
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Auditoria das ações feitas pelo infoprodutor (e equipe), incluindo falhas com o motivo para avaliação. Visível apenas para o operador.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                :class="
                    !group
                        ? 'bg-[var(--color-primary)]/20 text-zinc-900 dark:text-white'
                        : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'
                "
                @click="selectGroup('')"
            >
                Todas
            </button>
            <button
                v-for="g in group_options"
                :key="g.value"
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                :class="
                    group === g.value
                        ? 'bg-[var(--color-primary)]/20 text-zinc-900 dark:text-white'
                        : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'
                "
                @click="selectGroup(g.value)"
            >
                {{ g.label }}
            </button>
        </div>

        <form
            class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40 sm:grid-cols-2 lg:grid-cols-6"
            @submit.prevent="applyFilters"
        >
            <label class="text-xs text-zinc-500">
                Infoprodutor
                <select
                    v-model="merchantId"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="">Todos</option>
                    <option v-for="m in merchants" :key="m.id" :value="String(m.id)">
                        {{ m.name }} — {{ m.email }}
                    </option>
                </select>
            </label>
            <label class="text-xs text-zinc-500">
                Ação
                <select
                    v-model="action"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="">Todas</option>
                    <option v-for="opt in filteredActionOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </label>
            <label class="text-xs text-zinc-500">
                Data inicial
                <input
                    v-model="dateFrom"
                    type="date"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </label>
            <label class="text-xs text-zinc-500">
                Data final
                <input
                    v-model="dateTo"
                    type="date"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </label>
            <label class="text-xs text-zinc-500">
                Por página
                <select
                    v-model="perPage"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>
            <div class="flex items-end gap-2">
                <Button type="submit" class="flex-1">Filtrar</Button>
                <Button type="button" variant="secondary" class="flex-1" @click="clearFilters">Limpar</Button>
            </div>
        </form>

        <div v-if="rows.length" class="space-y-3 md:hidden">
            <button
                v-for="log in rows"
                :key="`mobile-${log.id}`"
                type="button"
                class="block w-full rounded-2xl border bg-white p-4 text-left shadow-sm dark:bg-zinc-900/40"
                :class="
                    isFailureLog(log)
                        ? 'border-red-300 dark:border-red-800'
                        : 'border-zinc-200 dark:border-zinc-700'
                "
                @click="toggleExpanded(log.id)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-sm font-semibold"
                            :class="isFailureLog(log) ? 'text-red-700 dark:text-red-300' : 'text-zinc-900 dark:text-white'"
                        >
                            {{ log.summary }}
                        </p>
                        <p class="mt-0.5 truncate text-xs text-zinc-500">{{ log.merchant?.name }}</p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="
                            isFailureLog(log)
                                ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300'
                                : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                        "
                    >
                        {{ isFailureLog(log) ? 'Falha' : log.group_label }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">{{ formatDateTime(log.created_at) }} · {{ sourceLabel(log.source) }}</p>
                <dl v-if="expandedId === log.id && metadataEntries(log.metadata).length" class="mt-3 space-y-1 border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800">
                    <div v-for="[k, v] in metadataEntries(log.metadata)" :key="k" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">{{ metaLabel(k) }}</dt>
                        <dd class="break-all text-zinc-800 dark:text-zinc-200">{{ formatMetaValue(v) }}</dd>
                    </div>
                </dl>
            </button>
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Infoprodutor</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Quem fez</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Ação</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Origem</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-500">Detalhes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <template v-for="log in rows" :key="log.id">
                            <tr
                                class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30"
                                :class="isFailureLog(log) ? 'bg-red-50/70 dark:bg-red-950/20' : ''"
                            >
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ formatDateTime(log.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ log.merchant?.name }}</p>
                                    <p class="text-xs text-zinc-500">{{ log.merchant?.email }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <template v-if="log.actor">
                                        <p class="text-zinc-900 dark:text-white">{{ log.actor.name }}</p>
                                        <p class="text-xs text-zinc-500">{{ log.actor.role_label }} · {{ log.actor.email }}</p>
                                    </template>
                                    <span v-else class="text-zinc-400">—</span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <p
                                        class="font-medium"
                                        :class="isFailureLog(log) ? 'text-red-700 dark:text-red-300' : 'text-zinc-900 dark:text-white'"
                                    >
                                        {{ log.summary }}
                                    </p>
                                    <p class="text-xs" :class="isFailureLog(log) ? 'text-red-600 dark:text-red-400' : 'text-zinc-500'">
                                        {{ isFailureLog(log) ? 'Falha · ' + log.group_label : log.group_label }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ sourceLabel(log.source) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-primary)] hover:underline"
                                        @click="toggleExpanded(log.id)"
                                    >
                                        {{ expandedId === log.id ? 'Ocultar' : 'Ver' }}
                                        <ChevronUp v-if="expandedId === log.id" class="h-4 w-4" />
                                        <ChevronDown v-else class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expandedId === log.id">
                                <td colspan="6" class="bg-zinc-50 px-4 py-3 text-sm dark:bg-zinc-800/40">
                                    <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        <div v-for="[k, v] in metadataEntries(log.metadata)" :key="k">
                                            <dt class="text-xs uppercase text-zinc-500">{{ metaLabel(k) }}</dt>
                                            <dd class="break-all text-zinc-800 dark:text-zinc-200">{{ formatMetaValue(v) }}</dd>
                                        </div>
                                        <div v-if="log.ip">
                                            <dt class="text-xs uppercase text-zinc-500">IP</dt>
                                            <dd>{{ log.ip }}</dd>
                                        </div>
                                    </dl>
                                    <p v-if="!metadataEntries(log.metadata).length && !log.ip" class="text-zinc-500">Sem detalhes adicionais.</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!rows.length" class="rounded-xl border border-dashed border-zinc-200 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhum log encontrado para estes filtros.
        </div>

        <div v-if="paginationLinks.length > 3" class="flex flex-wrap justify-center gap-2">
            <Link
                v-for="(l, idx) in paginationLinks"
                :key="idx"
                :href="l.url || '#'"
                class="rounded-lg px-3 py-1.5 text-sm"
                :class="l.active ? 'bg-[var(--color-primary)]/20 font-semibold' : 'bg-zinc-100 dark:bg-zinc-800'"
            >
                <span v-text="htmlToText(l.label)" />
            </Link>
        </div>
    </div>
</template>
