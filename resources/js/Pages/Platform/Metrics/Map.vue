<script setup>
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import MetricsNav from '@/components/metrics/MetricsNav.vue';
import MetricsGeoMap from '@/components/metrics/MetricsGeoMap.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';

defineOptions({ layout: LayoutPlatform });
const { pageClass, innerPanelClass, filterPanelClass } = usePanelThemeClasses();

const props = defineProps({
    period: String,
    date_from: String,
    date_to: String,
    filters: { type: Object, default: () => ({}) },
    seller_id: { type: [Number, String], default: null },
    sellers: { type: Array, default: () => [] },
    metric: { type: String, default: 'conversions' },
    points: { type: Array, default: () => [] },
    by_city: { type: Array, default: () => [] },
    by_region: { type: Array, default: () => [] },
    by_country: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    tab: { type: String, default: 'map' },
    base_path: { type: String, default: '/plataforma/metricas' },
});

function money(v) {
    return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function reload(extra = {}) {
    const q = { period: props.period, metric: props.metric, ...props.filters, ...extra };
    if (props.seller_id) q.seller_id = props.seller_id;
    if (props.period === 'personalizado') {
        q.date_from = props.date_from;
        q.date_to = props.date_to;
    }
    Object.keys(q).forEach((k) => { if (q[k] === '' || q[k] == null) delete q[k]; });
    router.get(`${props.base_path}/mapa`, q, { preserveState: false });
}
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            title="Mapa de conversões"
            subtitle="Visão geográfica global da plataforma — filtre por infoprodutor."
        />
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
                        :value="metric"
                        @change="reload({ metric: $event.target.value })"
                    >
                        <option value="conversions">Tamanho: conversões</option>
                        <option value="events">Tamanho: eventos</option>
                        <option value="revenue">Tamanho: receita</option>
                    </select>
                    <select
                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                        :value="filters.product_id || ''"
                        @change="reload({ product_id: $event.target.value || null })"
                    >
                        <option value="">Todos os produtos</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </template>
            </MetricsNav>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div :class="[innerPanelClass, 'p-3 text-sm']">
                    <div class="text-zinc-500">Pontos no mapa</div>
                    <div class="text-lg font-semibold">{{ points.length }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3 text-sm']">
                    <div class="text-zinc-500">Eventos com coordenadas</div>
                    <div class="text-lg font-semibold">{{ totals.with_coords || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3 text-sm']">
                    <div class="text-zinc-500">Sem geo (ainda)</div>
                    <div class="text-lg font-semibold">{{ totals.without_coords || 0 }}</div>
                </div>
                <div :class="[innerPanelClass, 'p-3 text-sm']">
                    <div class="text-zinc-500">Receita no período</div>
                    <div class="text-lg font-semibold">{{ money(totals.revenue) }}</div>
                </div>
            </div>

            <MetricsGeoMap :points="points" :metric="metric" />

            <div class="grid gap-4 lg:grid-cols-3">
                <div v-for="block in [
                    { title: 'Top cidades', rows: by_city },
                    { title: 'Top estados', rows: by_region },
                    { title: 'Top países', rows: by_country },
                ]" :key="block.title" :class="[filterPanelClass, 'overflow-hidden']">
                    <div class="border-b border-zinc-200 px-4 py-3 text-sm font-semibold dark:border-zinc-700">{{ block.title }}</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                                <tr>
                                    <th class="px-3 py-2">Local</th>
                                    <th class="px-3 py-2">Visit.</th>
                                    <th class="px-3 py-2">Conv.</th>
                                    <th class="px-3 py-2">Receita</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in block.rows.slice(0, 12)" :key="r.label" class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="px-3 py-2">{{ r.label }}</td>
                                    <td class="px-3 py-2">{{ r.visitors }}</td>
                                    <td class="px-3 py-2">{{ r.conversions }} ({{ r.conversion_rate }}%)</td>
                                    <td class="px-3 py-2">{{ money(r.revenue) }}</td>
                                </tr>
                                <tr v-if="!block.rows.length">
                                    <td colspan="4" class="px-3 py-6 text-center text-zinc-500">Sem dados.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
