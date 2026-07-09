<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AfiliadoTabs from '@/components/afiliados/AfiliadoTabs.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { CircleDollarSign, ShoppingCart, TrendingUp, Package, Clock } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    period: { type: String, default: 'mes' },
    stats: { type: Object, default: () => ({}) },
    grafico_comissao: { type: Array, default: () => [] },
    comissao_por_produto: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    producers: { type: Array, default: () => [] },
});

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: '7 dias' },
    { value: 'mes', label: 'Mês' },
    { value: 'ano', label: 'Ano' },
    { value: 'total', label: 'Total' },
];

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}

function setPeriod(period) {
    router.get('/produtos/afiliados/relatorios', { period, ...props.filters }, { preserveState: true, replace: true });
}

const chartSeries = computed(() => [{
    name: 'Comissão',
    data: props.grafico_comissao.map((r) => r.total),
}]);
const chartOptions = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit' },
    xaxis: { categories: props.grafico_comissao.map((r) => r.date) },
    colors: ['var(--color-primary)'],
    dataLabels: { enabled: false },
}));
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Relatórios do afiliado</h1>
            <p class="mt-1 text-sm text-zinc-500">Métricas das suas comissões por período.</p>
        </div>
        <AfiliadoTabs />
        <div class="flex flex-wrap gap-2">
            <button
                v-for="opt in periodOptions"
                :key="opt.value"
                type="button"
                class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                :class="period === opt.value ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'"
                @click="setPeriod(opt.value)"
            >
                {{ opt.label }}
            </button>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AuroraStatCard label="Receita de comissão" :value="formatBRL(stats.receita_comissao)" :icon="CircleDollarSign" />
            <AuroraStatCard label="Vendas" :value="String(stats.total_vendas ?? 0)" :icon="ShoppingCart" />
            <AuroraStatCard label="Ticket médio" :value="formatBRL(stats.ticket_medio)" :icon="TrendingUp" />
            <AuroraStatCard label="Produtos vendidos" :value="String(stats.produtos_vendidos ?? 0)" :icon="Package" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <AuroraStatCard label="Pendente" :value="formatBRL(stats.comissao_pendente)" :icon="Clock" />
            <AuroraStatCard
                label="Conversão"
                :value="stats.taxa_conversao != null ? `${stats.taxa_conversao}%` : '—'"
                :icon="TrendingUp"
            />
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold">Comissão por período</h2>
                <VueApexCharts v-if="grafico_comissao.length" type="area" height="260" :options="chartOptions" :series="chartSeries" />
                <p v-else class="text-sm text-zinc-500">Nenhum dado no período.</p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold">Comissão por produto</h2>
                <ul v-if="comissao_por_produto.length" class="space-y-3 text-sm">
                    <li v-for="p in comissao_por_produto" :key="p.product_id" class="flex justify-between">
                        <span>{{ p.product_name }}</span>
                        <span class="font-medium">{{ formatBRL(p.total) }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-zinc-500">Nenhum dado no período.</p>
            </div>
        </div>
    </div>
</template>
