<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import MetricsNav from '@/components/metrics/MetricsNav.vue';
import AfiliadoTabs from '@/components/afiliados/AfiliadoTabs.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';

defineOptions({ layout: LayoutInfoprodutor });
const { pageClass, filterPanelClass } = usePanelThemeClasses();

const props = defineProps({
    period: String,
    date_from: String,
    date_to: String,
    filters: { type: Object, default: () => ({}) },
    q: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    pagination: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    tab: { type: String, default: 'clicks' },
    base_path: { type: String, default: '/produtos/afiliados/metricas' },
});

const search = ref(props.q || '');

function money(v) {
    if (v == null) return '—';
    return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function fmtDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('pt-BR');
    } catch {
        return iso;
    }
}

function reload(extra = {}) {
    const q = {
        period: props.period,
        ...props.filters,
        q: search.value || undefined,
        page: undefined,
        ...extra,
    };
    if (props.period === 'personalizado') {
        q.date_from = props.date_from;
        q.date_to = props.date_to;
    }
    Object.keys(q).forEach((k) => { if (q[k] === '' || q[k] == null) delete q[k]; });
    router.get(`${props.base_path}/cliques`, q, { preserveState: false });
}


function goPage(page) {
    reload({ page });
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader title="Afiliado - Cliques" subtitle="Log detalhado com IP mascarado (LGPD)." />
        <div class="mt-4 space-y-4">
            <AfiliadoTabs />
            <MetricsNav :tab="tab" :period="period" :date_from="date_from" :date_to="date_to" :filters="filters" :base-path="base_path">
                <template #filters>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Buscar campanha, URL, cidade..."
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                        @keyup.enter="reload()"
                    >
                </template>
            </MetricsNav>

            <div :class="[filterPanelClass, 'overflow-hidden']">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-zinc-50 uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-3 py-2">Data</th>
                                <th class="px-3 py-2">Evento</th>
                                <th class="px-3 py-2">IP</th>
                                <th class="px-3 py-2">Produto</th>
                                <th class="px-3 py-2">URL</th>
                                <th class="px-3 py-2">Fonte</th>
                                <th class="px-3 py-2">Campanha</th>
                                <th class="px-3 py-2">Disp.</th>
                                <th class="px-3 py-2">Local</th>
                                <th class="px-3 py-2">Afiliado</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Valor</th>
                                <th class="px-3 py-2">Tempo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.id" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="whitespace-nowrap px-3 py-2">{{ fmtDate(r.occurred_at) }}</td>
                                <td class="px-3 py-2">{{ r.event_name }}</td>
                                <td class="px-3 py-2 font-mono">{{ r.ip_masked || '—' }}</td>
                                <td class="max-w-[140px] truncate px-3 py-2">{{ r.product_name || '—' }}</td>
                                <td class="max-w-[180px] truncate px-3 py-2" :title="r.destination_url">{{ r.destination_url || '—' }}</td>
                                <td class="px-3 py-2">{{ r.utm_source || '—' }}</td>
                                <td class="px-3 py-2">{{ r.utm_campaign || '—' }}</td>
                                <td class="px-3 py-2">{{ r.device_type || '—' }}</td>
                                <td class="px-3 py-2">{{ [r.city, r.region].filter(Boolean).join('/') || '—' }}</td>
                                <td class="px-3 py-2">{{ r.affiliate_ref || '—' }}</td>
                                <td class="px-3 py-2">{{ r.conversion_status || '—' }}</td>
                                <td class="px-3 py-2">{{ money(r.amount) }}</td>
                                <td class="px-3 py-2">{{ r.seconds_to_convert != null ? `${r.seconds_to_convert}s` : '—' }}</td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="13" class="px-3 py-8 text-center text-zinc-500">Nenhum evento no período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                    <span class="text-zinc-500">Página {{ pagination.current_page }} de {{ pagination.last_page }} ({{ pagination.total }})</span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1 disabled:opacity-40"
                            :disabled="pagination.current_page <= 1"
                            @click="goPage(pagination.current_page - 1)"
                        >Anterior</button>
                        <button
                            type="button"
                            class="rounded-lg border px-3 py-1 disabled:opacity-40"
                            :disabled="pagination.current_page >= pagination.last_page"
                            @click="goPage(pagination.current_page + 1)"
                        >Próxima</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
