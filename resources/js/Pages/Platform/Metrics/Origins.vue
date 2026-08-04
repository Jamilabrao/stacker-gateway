<script setup>
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import MetricsNav from '@/components/metrics/MetricsNav.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';

defineOptions({ layout: LayoutPlatform });
const { pageClass, filterPanelClass } = usePanelThemeClasses();

const props = defineProps({
    period: String,
    date_from: String,
    date_to: String,
    filters: { type: Object, default: () => ({}) },
    seller_id: { type: [Number, String], default: null },
    sellers: { type: Array, default: () => [] },
    dimension: { type: String, default: 'tenant_id' },
    rows: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    tab: { type: String, default: 'origins' },
    base_path: { type: String, default: '/plataforma/metricas' },
});

const dimensions = [
    { value: 'tenant_id', label: 'Infoprodutor' },
    { value: 'utm_source', label: 'Fonte' },
    { value: 'utm_medium', label: 'Mídia' },
    { value: 'utm_campaign', label: 'Campanha' },
    { value: 'utm_content', label: 'Conteúdo' },
    { value: 'utm_term', label: 'Termo' },
    { value: 'referrer', label: 'Referrer' },
    { value: 'product_id', label: 'Produto' },
    { value: 'device_type', label: 'Dispositivo' },
    { value: 'country', label: 'País' },
    { value: 'region', label: 'Estado' },
    { value: 'city', label: 'Cidade' },
    { value: 'affiliate_ref', label: 'Afiliado' },
    { value: 'campaign_code', label: 'Cód. campanha' },
];

function money(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function reload(extra = {}) {
    const q = {
        period: props.period,
        dimension: props.dimension,
        ...props.filters,
        ...extra,
    };
    if (props.seller_id) q.seller_id = props.seller_id;
    if (props.period === 'personalizado') {
        q.date_from = props.date_from;
        q.date_to = props.date_to;
    }
    Object.keys(q).forEach((k) => { if (q[k] === '' || q[k] == null) delete q[k]; });
    router.get(`${props.base_path}/origem`, q, { preserveState: false });
}

function exportParams() {
    const params = new URLSearchParams({
        period: props.period || '7dias',
        type: 'origins',
        dimension: props.dimension || 'tenant_id',
    });
    if (props.seller_id) params.set('seller_id', String(props.seller_id));
    if (props.filters.product_id) params.set('product_id', props.filters.product_id);
    if (props.date_from) params.set('date_from', props.date_from);
    if (props.date_to) params.set('date_to', props.date_to);
    return params;
}

function exportCsv() {
    window.location.href = `${props.base_path}/export.csv?${exportParams().toString()}`;
}

function exportXlsx() {
    window.location.href = `${props.base_path}/export.xlsx?${exportParams().toString()}`;
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader title="Origem das vendas" subtitle="Performance por infoprodutor, fonte, campanha e local." />
        <div class="mt-4 space-y-4">
            <MetricsNav
                :tab="tab"
                :period="period"
                :date_from="date_from"
                :date_to="date_to"
                :filters="filters"
                :base-path="base_path"
                :sellers="sellers"
                :seller-id="seller_id"
            >
                <template #filters>
                    <select
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                        :value="dimension"
                        @change="reload({ dimension: $event.target.value })"
                    >
                        <option v-for="d in dimensions" :key="d.value" :value="d.value">{{ d.label }}</option>
                    </select>
                    <select
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                        :value="filters.product_id || ''"
                        @change="reload({ product_id: $event.target.value || null })"
                    >
                        <option value="">Todos os produtos</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <button type="button" class="rounded-lg bg-zinc-200 px-3 py-1.5 text-xs font-medium dark:bg-zinc-700" @click="exportCsv">
                        Exportar CSV
                    </button>
                    <button type="button" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white" @click="exportXlsx">
                        Exportar Excel
                    </button>
                </template>
            </MetricsNav>

            <div :class="[filterPanelClass, 'overflow-hidden']">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-3 py-2">Dimensão</th>
                                <th class="px-3 py-2">Visitantes</th>
                                <th class="px-3 py-2">Cliques</th>
                                <th class="px-3 py-2">Checkouts</th>
                                <th class="px-3 py-2">PIX</th>
                                <th class="px-3 py-2">Vendas</th>
                                <th class="px-3 py-2">Conv.%</th>
                                <th class="px-3 py-2">Receita</th>
                                <th class="px-3 py-2">Ticket</th>
                                <th class="px-3 py-2">R$/clique</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.key" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="px-3 py-2 font-medium">{{ r.label }}</td>
                                <td class="px-3 py-2">{{ r.visitors }}</td>
                                <td class="px-3 py-2">{{ r.clicks }}</td>
                                <td class="px-3 py-2">{{ r.checkouts_started }}</td>
                                <td class="px-3 py-2">{{ r.pix_created }}</td>
                                <td class="px-3 py-2">{{ r.approved }}</td>
                                <td class="px-3 py-2">{{ r.conversion_rate }}%</td>
                                <td class="px-3 py-2">{{ money(r.revenue) }}</td>
                                <td class="px-3 py-2">{{ money(r.avg_ticket) }}</td>
                                <td class="px-3 py-2">{{ money(r.revenue_per_click) }}</td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="10" class="px-3 py-8 text-center text-zinc-500">Sem dados no período.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
