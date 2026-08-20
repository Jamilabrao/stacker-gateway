<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { ChevronDown, ChevronUp, Copy, Check, Webhook } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    webhooks: { type: Object, required: true },
    acquirers: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ gateway: null, q: null, per_page: 25 }),
    },
});

const activeTab = ref('recebidos');
const gateway = ref(props.filters.gateway ? String(props.filters.gateway) : '');
const searchQ = ref(props.filters.q ? String(props.filters.q) : '');
const perPage = ref(String(props.filters.per_page || 25));
const expandedId = ref(null);
const copiedId = ref(null);

watch(
    () => props.filters,
    (f) => {
        gateway.value = f.gateway ? String(f.gateway) : '';
        searchQ.value = f.q ? String(f.q) : '';
        perPage.value = String(f.per_page || 25);
    }
);

const rows = computed(() => (Array.isArray(props.webhooks?.data) ? props.webhooks.data : []));
const paginationLinks = computed(() => (Array.isArray(props.webhooks?.links) ? props.webhooks.links : []));

function listingQuery(overrides = {}) {
    const query = {
        gateway: (overrides.gateway !== undefined ? overrides.gateway : gateway.value) || undefined,
        q: (overrides.q !== undefined ? overrides.q : searchQ.value)?.trim() || undefined,
        per_page: Number(overrides.per_page !== undefined ? overrides.per_page : perPage.value) || 25,
    };
    if (overrides.page) {
        query.page = Number(overrides.page);
    }
    return query;
}

function applyFilters() {
    router.get('/plataforma/webhooks', listingQuery({ page: 1 }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    gateway.value = '';
    searchQ.value = '';
    perPage.value = '25';
    router.get('/plataforma/webhooks', {}, { preserveState: true, replace: true });
}

function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('pt-BR');
}

function payloadText(row) {
    try {
        return JSON.stringify(row?.payload ?? {}, null, 2);
    } catch {
        return '{}';
    }
}

function toggleExpanded(id) {
    expandedId.value = expandedId.value === id ? null : id;
}

async function copyPayload(row) {
    try {
        await navigator.clipboard.writeText(payloadText(row));
        copiedId.value = row.id;
        setTimeout(() => {
            if (copiedId.value === row.id) copiedId.value = null;
        }, 2000);
    } catch {
        copiedId.value = null;
    }
}

function statusClass(status) {
    if (status == null) return 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300';
    if (status >= 200 && status < 300) return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300';
    if (status >= 400) return 'bg-red-50 text-red-800 dark:bg-red-950/50 dark:text-red-300';
    return 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Webhooks</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Eventos enviados pelos adquirentes para esta instalação. Use o filtro para conferir o payload recebido.
            </p>
        </div>

        <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
            <nav class="inline-flex w-max rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80" aria-label="Abas de Webhooks">
                <button
                    type="button"
                    :aria-current="activeTab === 'recebidos' ? 'page' : undefined"
                    class="flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200 bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]"
                    @click="activeTab = 'recebidos'"
                >
                    <Webhook class="h-4 w-4 shrink-0" aria-hidden="true" />
                    Webhooks recebidos
                </button>
            </nav>
        </div>

        <form
            class="grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40 sm:grid-cols-2 lg:grid-cols-5"
            @submit.prevent="applyFilters"
        >
            <label class="text-xs text-zinc-500">
                Adquirente
                <select
                    v-model="gateway"
                    class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                >
                    <option value="">Todos</option>
                    <option v-for="a in acquirers" :key="a.slug" :value="a.slug">
                        {{ a.name }}
                    </option>
                </select>
            </label>
            <label class="text-xs text-zinc-500 sm:col-span-1 lg:col-span-2">
                Evento ou ID
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="charge.paid, ch_…"
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

        <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 md:block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Data</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Adquirente</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">Evento</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-500">HTTP</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-500">Payload</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <template v-for="row in rows" :key="row.id">
                            <tr class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/30">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ formatDateTime(row.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ row.gateway_name }}</p>
                                    <p class="text-xs text-zinc-500">{{ row.http_method }} {{ row.path }}</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-zinc-800 dark:text-zinc-200">
                                    {{ row.event || '—' }}
                                </td>
                                <td class="max-w-[180px] truncate px-4 py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300" :title="row.transaction_id || ''">
                                    {{ row.transaction_id || '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(row.http_status)">
                                        {{ row.http_status ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-sm font-medium text-[var(--color-primary)] hover:underline"
                                        @click="toggleExpanded(row.id)"
                                    >
                                        {{ expandedId === row.id ? 'Ocultar' : 'Ver' }}
                                        <ChevronUp v-if="expandedId === row.id" class="h-4 w-4" />
                                        <ChevronDown v-else class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="expandedId === row.id">
                                <td colspan="6" class="bg-zinc-50 px-4 py-3 dark:bg-zinc-800/40">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <p class="text-xs uppercase text-zinc-500">Resposta recebida</p>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300"
                                            @click="copyPayload(row)"
                                        >
                                            <Check v-if="copiedId === row.id" class="h-3.5 w-3.5 text-emerald-600" />
                                            <Copy v-else class="h-3.5 w-3.5" />
                                            {{ copiedId === row.id ? 'Copiado' : 'Copiar JSON' }}
                                        </button>
                                    </div>
                                    <pre class="max-h-80 overflow-auto rounded-xl border border-zinc-200 bg-white p-3 text-xs text-zinc-800 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200">{{ payloadText(row) }}</pre>
                                    <p v-if="row.ip" class="mt-2 text-xs text-zinc-500">IP {{ row.ip }}</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="rows.length" class="space-y-3 md:hidden">
            <button
                v-for="row in rows"
                :key="`m-${row.id}`"
                type="button"
                class="block w-full rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40"
                @click="toggleExpanded(row.id)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ row.gateway_name }}</p>
                        <p class="mt-0.5 truncate text-xs text-zinc-500">{{ row.event || 'sem evento' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(row.http_status)">
                        {{ row.http_status ?? '—' }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">{{ formatDateTime(row.created_at) }}</p>
                <pre
                    v-if="expandedId === row.id"
                    class="mt-3 max-h-64 overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-[11px] dark:border-zinc-700 dark:bg-zinc-950"
                >{{ payloadText(row) }}</pre>
            </button>
        </div>

        <div v-if="!rows.length" class="rounded-xl border border-dashed border-zinc-200 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhum webhook recebido ainda. Assim que um adquirente notificar esta URL, o evento aparece aqui.
        </div>

        <div v-if="paginationLinks.length > 3" class="flex flex-wrap justify-center gap-2">
            <Link
                v-for="(l, idx) in paginationLinks"
                :key="idx"
                :href="l.url || '#'"
                class="rounded-lg px-3 py-1.5 text-sm"
                :class="l.active ? 'bg-[var(--color-primary)]/20 font-semibold' : 'bg-zinc-100 dark:bg-zinc-800'"
                :preserve-scroll="true"
            >
                <span v-html="l.label" />
            </Link>
        </div>
    </div>
</template>
