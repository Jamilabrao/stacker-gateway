<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import {
    Wallet,
    CircleDollarSign,
    Users,
    ArrowDownCircle,
    ShoppingCart,
    Eye,
    EyeOff,
    Receipt,
    UserPlus,
    UserCheck,
    Repeat,
    Package,
    TrendingUp,
    CreditCard,
    Building2,
    Filter,
    AlertTriangle,
    ExternalLink,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    period: { type: String, default: 'hoje' },
    kpis: {
        type: Object,
        default: () => ({
            wallet_available: 0,
            wallet_pending: 0,
            vendas_totais: 0,
            quantidade_vendas: 0,
            ticket_medio: 0,
            withdrawals_total: 0,
            withdrawals_pending: 0,
            withdrawals_paid_count: 0,
            withdrawals_pending_count: 0,
            infoprodutores_count: 0,
            faturamento_taxas_cobradas: 0,
            faturamento_custo_adquirente_vendas: 0,
            faturamento_custo_adquirente_saques: 0,
            faturamento_liquido: 0,
        }),
    },
    growth: {
        type: Object,
        default: () => ({
            novos_infoprodutores: 0,
            infoprodutores_ativos: 0,
            infoprodutores_total: 0,
            infoprodutores_com_vendas: 0,
            novos_compradores: 0,
            compradores_recorrentes: 0,
            produtos_criados: 0,
            taxa_aprovacao: 0,
        }),
    },
    comparisons: { type: Object, default: null },
    funnel: {
        type: Object,
        default: () => ({ tentativas: 0, taxa_aprovacao: 0, items: [] }),
    },
    payment_methods: { type: Array, default: () => [] },
    acquirers: { type: Array, default: () => [] },
    top_sellers: { type: Array, default: () => [] },
    top_products: { type: Array, default: () => [] },
    alerts: { type: Array, default: () => [] },
    grafico: {
        type: Object,
        default: () => ({ granularity: 'hour', compare: false, points: [] }),
    },
    grafico_vendas: { type: Array, default: () => [] },
    ultimas_transacoes: { type: Array, default: () => [] },
});

const valuesVisible = ref(true);
const isDarkMode = ref(false);
const chartMetric = ref('volume');
const showPreviousSeries = ref(true);
const sellerSort = ref('volume');
const productSort = ref('volume');

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: '7 dias' },
    { value: 'mes', label: 'Mês' },
    { value: 'ano', label: 'Ano' },
    { value: 'total', label: 'Total' },
];

const chartMetrics = [
    { value: 'volume', label: 'Faturamento' },
    { value: 'count', label: 'Nº de vendas' },
    { value: 'revenue', label: 'Receita da plataforma' },
    { value: 'ticket', label: 'Ticket médio' },
    { value: 'refunds', label: 'Reembolsos' },
];

const statusLabels = {
    completed: 'Concluída',
    pending: 'Pendente',
    rejected: 'Recusada',
    cancelled: 'Cancelada',
    refunded: 'Reembolsada',
    disputed: 'Em disputa',
    refund_pending: 'Reembolso pendente',
};

function setPeriod(value) {
    router.get('/plataforma/dashboard', { period: value }, { preserveState: false });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatNumber(value) {
    return new Intl.NumberFormat('pt-BR').format(value ?? 0);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? formatNumber(value) : '••••';
}

function deltaClass(comparison) {
    if (!comparison) return 'text-zinc-500';
    if (comparison.delta_percent === null) return 'text-emerald-500';
    if (comparison.delta_percent > 0) return 'text-emerald-500';
    if (comparison.delta_percent < 0) return 'text-rose-500';
    return 'text-zinc-500';
}

function deltaLabel(comparison) {
    if (!comparison) return '';
    if (comparison.delta_percent === null) {
        return 'Novo vs. período anterior';
    }
    const n = Number(comparison.delta_percent);
    const arrow = n > 0 ? '↑' : n < 0 ? '↓' : '→';
    return `${arrow} ${Math.abs(n).toFixed(1)}% vs. período anterior`;
}

const chartPoints = computed(() => props.grafico?.points?.length
    ? props.grafico.points
    : (props.grafico_vendas || []).map((d) => ({
        key: d.data,
        label: d.data,
        volume: d.total,
        count: 0,
        ticket: 0,
        revenue: 0,
        refunds: 0,
        prev_volume: 0,
        prev_count: 0,
        prev_ticket: 0,
        prev_revenue: 0,
        prev_refunds: 0,
    })));

const canCompareChart = computed(() => Boolean(props.grafico?.compare) && props.period !== 'total');

const chartSeries = computed(() => {
    const metric = chartMetric.value;
    const currentKey = metric;
    const prevKey = `prev_${metric}`;
    const current = {
        name: chartMetrics.find((m) => m.value === metric)?.label || 'Atual',
        data: valuesVisible.value
            ? chartPoints.value.map((p) => Number(p[currentKey] ?? 0))
            : chartPoints.value.map(() => 0),
    };
    if (canCompareChart.value && showPreviousSeries.value) {
        return [
            current,
            {
                name: 'Período anterior',
                data: valuesVisible.value
                    ? chartPoints.value.map((p) => Number(p[prevKey] ?? 0))
                    : chartPoints.value.map(() => 0),
            },
        ];
    }
    return [current];
});

const isMoneyMetric = computed(() => ['volume', 'ticket', 'revenue', 'refunds'].includes(chartMetric.value));

const chartOptions = computed(() => {
    const manyPoints = chartPoints.value.length > 14;
    return {
        chart: {
            type: 'area',
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 600 },
        },
        colors: ['var(--color-primary)', '#71717a'],
        dataLabels: {
            enabled: !manyPoints && valuesVisible.value && chartSeries.value.length === 1,
            formatter: (v) => (isMoneyMetric.value ? formatBRL(v) : formatNumber(v)),
            style: { fontSize: '11px' },
            offsetY: -4,
        },
        stroke: { curve: 'smooth', width: [2.5, 2], dashArray: [0, 6] },
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.3,
                opacityFrom: 0.45,
                opacityTo: 0.06,
            },
        },
        markers: {
            size: manyPoints ? 0 : 4,
            strokeWidth: 2,
            hover: { size: 6 },
        },
        xaxis: {
            categories: chartPoints.value.map((p) => p.label || p.key),
            labels: { style: { colors: '#71717a', fontSize: '12px' } },
            axisBorder: { show: true },
            crosshairs: { show: true },
        },
        yaxis: {
            labels: {
                style: { colors: '#71717a', fontSize: '12px' },
                formatter: (v) => (isMoneyMetric.value ? formatBRL(v) : formatNumber(v)),
            },
        },
        legend: {
            show: chartSeries.value.length > 1,
            labels: { colors: '#a1a1aa' },
        },
        grid: {
            borderColor: 'var(--chart-grid, #e4e4e7)',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
            padding: { top: 20, right: 10, bottom: 0, left: 0 },
        },
        tooltip: {
            theme: isDarkMode.value ? 'dark' : 'light',
            shared: true,
            intersect: false,
            y: {
                formatter: (v) => {
                    if (!valuesVisible.value) return '••••••';
                    return isMoneyMetric.value ? formatBRL(v) : formatNumber(v);
                },
            },
            style: { fontSize: '13px' },
        },
    };
});

const sortedSellers = computed(() => {
    const rows = [...(props.top_sellers || [])];
    rows.sort((a, b) => (sellerSort.value === 'quantidade' ? b.quantidade - a.quantidade : b.volume - a.volume));
    return rows.slice(0, 10);
});

const sortedProducts = computed(() => {
    const rows = [...(props.top_products || [])];
    rows.sort((a, b) => (productSort.value === 'quantidade' ? b.quantidade - a.quantidade : b.volume - a.volume));
    return rows.slice(0, 10);
});

const visibleFunnelItems = computed(() => {
    const items = props.funnel?.items || [];
    const always = new Set(['completed', 'rejected', 'pending', 'cancelled', 'refunded']);
    return items.filter((item) => always.has(item.key) || item.quantidade > 0);
});

const paymentMax = computed(() => Math.max(1, ...props.payment_methods.map((m) => Number(m.total) || 0)));
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Visão consolidada</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Todos os tenants · acompanhamento financeiro, comercial e operacional</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" />
                    <EyeOff v-else class="h-5 w-5" />
                </button>
            </div>
        </div>

        <nav class="flex flex-wrap items-center gap-1" aria-label="Período">
            <button
                v-for="opt in periodOptions"
                :key="opt.value"
                type="button"
                :aria-current="period === opt.value ? 'true' : undefined"
                class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                :class="period === opt.value
                    ? 'bg-[var(--color-primary)] text-white'
                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                @click="setPeriod(opt.value)"
            >
                {{ opt.label }}
            </button>
        </nav>

        <section class="space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Financeiro</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <Wallet class="h-4 w-4 text-[var(--color-primary)]" />
                        Saldo disponível
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ displayCurrency(kpis.wallet_available) }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500">Estado atual das carteiras · pendente {{ displayCurrency(kpis.wallet_pending) }}</p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <CircleDollarSign class="h-4 w-4 text-[var(--color-primary)]" />
                        Volume vendido
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ displayCurrency(kpis.vendas_totais) }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500">{{ displayNumber(kpis.quantidade_vendas) }} pedidos · TM {{ displayCurrency(kpis.ticket_medio) }}</p>
                    <p v-if="comparisons?.vendas_totais" class="mt-1 text-xs font-medium" :class="deltaClass(comparisons.vendas_totais)">
                        {{ deltaLabel(comparisons.vendas_totais) }}
                    </p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <Receipt class="h-4 w-4 text-[var(--color-primary)]" />
                        Receita AsgardPay
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ displayCurrency(kpis.faturamento_liquido) }}
                    </p>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-500">
                        Taxas {{ displayCurrency(kpis.faturamento_taxas_cobradas) }}
                        <span class="hidden sm:inline"> · </span>
                        <span class="block sm:inline">Adq. vendas {{ displayCurrency(kpis.faturamento_custo_adquirente_vendas) }}</span>
                        <span class="hidden sm:inline"> · </span>
                        <span class="block sm:inline">Adq. saques {{ displayCurrency(kpis.faturamento_custo_adquirente_saques) }}</span>
                    </p>
                    <p v-if="comparisons?.faturamento_liquido" class="mt-1 text-xs font-medium" :class="deltaClass(comparisons.faturamento_liquido)">
                        {{ deltaLabel(comparisons.faturamento_liquido) }}
                    </p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <ArrowDownCircle class="h-4 w-4 text-[var(--color-primary)]" />
                        Saques
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        Concluídos: <span class="font-semibold">{{ displayCurrency(kpis.withdrawals_total) }}</span>
                        <span class="text-xs text-zinc-500"> · {{ displayNumber(kpis.withdrawals_paid_count) }}</span>
                    </p>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">
                        Pendentes: <span class="font-semibold">{{ displayCurrency(kpis.withdrawals_pending) }}</span>
                        <span class="text-xs text-zinc-500"> · {{ displayNumber(kpis.withdrawals_pending_count) }}</span>
                    </p>
                    <p class="mt-1 text-xs text-zinc-500">Pendentes = estado atual</p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <TrendingUp class="h-4 w-4 text-[var(--color-primary)]" />
                        Ticket médio
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ displayCurrency(kpis.ticket_medio) }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500">Volume aprovado / vendas aprovadas</p>
                    <p v-if="comparisons?.ticket_medio" class="mt-1 text-xs font-medium" :class="deltaClass(comparisons.ticket_medio)">
                        {{ deltaLabel(comparisons.ticket_medio) }}
                    </p>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Crescimento da plataforma</h3>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <UserPlus class="h-4 w-4 text-[var(--color-primary)]" />
                        Novos infoprodutores
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">+{{ displayNumber(growth.novos_infoprodutores) }}</p>
                    <p v-if="comparisons?.novos_infoprodutores" class="mt-1 text-xs font-medium" :class="deltaClass(comparisons.novos_infoprodutores)">
                        {{ deltaLabel(comparisons.novos_infoprodutores) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <UserCheck class="h-4 w-4 text-[var(--color-primary)]" />
                        Infoprodutores ativos
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ displayNumber(growth.infoprodutores_ativos) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Estado atual · {{ displayNumber(growth.infoprodutores_total) }} no cadastro</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <Users class="h-4 w-4 text-[var(--color-primary)]" />
                        Com vendas
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ displayNumber(growth.infoprodutores_com_vendas) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Sellers com venda aprovada no período</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <UserPlus class="h-4 w-4 text-[var(--color-primary)]" />
                        Novos compradores
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ displayNumber(growth.novos_compradores) }}</p>
                    <p v-if="comparisons?.novos_compradores" class="mt-1 text-xs font-medium" :class="deltaClass(comparisons.novos_compradores)">
                        {{ deltaLabel(comparisons.novos_compradores) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <Repeat class="h-4 w-4 text-[var(--color-primary)]" />
                        Compradores recorrentes
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ displayNumber(growth.compradores_recorrentes) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Já tinham compra aprovada antes do período</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                        <Filter class="h-4 w-4 text-[var(--color-primary)]" />
                        Taxa de aprovação
                    </div>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ growth.taxa_aprovacao }}%</p>
                    <p class="mt-1 text-xs text-zinc-500">Aprovadas / (aprovadas + recusadas)</p>
                    <p v-if="comparisons?.produtos_criados" class="mt-1 text-xs text-zinc-500">
                        +{{ displayNumber(growth.produtos_criados) }} produtos criados
                        <span v-if="comparisons.produtos_criados" :class="deltaClass(comparisons.produtos_criados)"> · {{ deltaLabel(comparisons.produtos_criados) }}</span>
                    </p>
                    <p v-else class="mt-1 text-xs text-zinc-500">+{{ displayNumber(growth.produtos_criados) }} produtos criados</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Performance no período</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="m in chartMetrics"
                        :key="m.value"
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors"
                        :class="chartMetric === m.value
                            ? 'bg-[var(--color-primary)] text-white'
                            : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                        @click="chartMetric = m.value"
                    >
                        {{ m.label }}
                    </button>
                    <label v-if="canCompareChart" class="ml-1 flex items-center gap-1.5 text-xs text-zinc-500">
                        <input v-model="showPreviousSeries" type="checkbox" class="rounded border-zinc-400">
                        Comparar período anterior
                    </label>
                </div>
            </div>
            <VueApexCharts type="area" height="280" :options="chartOptions" :series="chartSeries" />
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="mb-3 flex items-center gap-2">
                    <CreditCard class="h-4 w-4 text-zinc-500" />
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Meios de pagamento</h3>
                </div>
                <div v-if="payment_methods.length" class="space-y-3">
                    <div v-for="m in payment_methods" :key="m.metodo">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ m.label }}</span>
                            <span class="tabular-nums text-zinc-700 dark:text-zinc-300">{{ displayCurrency(m.total) }} · {{ m.percent }}%</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-full rounded-full bg-[var(--color-primary)]" :style="{ width: `${Math.min(100, (m.total / paymentMax) * 100)}%` }" />
                        </div>
                        <p class="mt-0.5 text-xs text-zinc-500">{{ displayNumber(m.quantidade) }} transações</p>
                    </div>
                </div>
                <p v-else class="py-6 text-center text-sm text-zinc-500">Sem vendas aprovadas no período.</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="mb-3 flex items-center gap-2">
                    <Building2 class="h-4 w-4 text-zinc-500" />
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Performance das adquirentes</h3>
                </div>
                <div class="overflow-x-auto">
                    <table v-if="acquirers.length" class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-zinc-500">
                            <tr>
                                <th class="pb-2 font-medium">Adquirente</th>
                                <th class="pb-2 text-right font-medium">Volume</th>
                                <th class="pb-2 text-right font-medium">Tx</th>
                                <th class="pb-2 text-right font-medium">Aprovação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in acquirers" :key="a.slug" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-2 text-zinc-800 dark:text-zinc-200" :title="`${a.aprovadas} aprovadas · ${a.recusadas} recusadas`">{{ a.nome }}</td>
                                <td class="py-2 text-right tabular-nums">{{ displayCurrency(a.volume) }}</td>
                                <td class="py-2 text-right tabular-nums text-zinc-500">{{ displayNumber(a.transacoes) }}</td>
                                <td class="py-2 text-right tabular-nums font-medium">{{ a.taxa_aprovacao }}%</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="py-6 text-center text-sm text-zinc-500">Nenhuma adquirente no período.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="mb-3 flex items-center gap-2">
                    <Filter class="h-4 w-4 text-zinc-500" />
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Funil de pagamentos</h3>
                </div>
                <p class="text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ displayNumber(funnel.tentativas) }} tentativas</p>
                <p class="mt-1 text-xs text-zinc-500">Taxa de aprovação {{ funnel.taxa_aprovacao }}%</p>
                <ul class="mt-4 space-y-2">
                    <li v-for="item in visibleFunnelItems" :key="item.key" class="flex items-center justify-between text-sm">
                        <span class="text-zinc-600 dark:text-zinc-300">{{ item.label }}</span>
                        <span class="tabular-nums text-zinc-800 dark:text-zinc-100">
                            {{ displayNumber(item.quantidade) }}
                            <span class="text-xs text-zinc-500"> — {{ item.percent }}%</span>
                        </span>
                    </li>
                </ul>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Top infoprodutores</h3>
                    <div class="flex items-center gap-2">
                        <button type="button" class="text-xs" :class="sellerSort === 'volume' ? 'font-semibold text-[var(--color-primary)]' : 'text-zinc-500'" @click="sellerSort = 'volume'">Faturamento</button>
                        <button type="button" class="text-xs" :class="sellerSort === 'quantidade' ? 'font-semibold text-[var(--color-primary)]' : 'text-zinc-500'" @click="sellerSort = 'quantidade'">Vendas</button>
                        <Link href="/plataforma/usuarios?sort_by=total_sales&sort_direction=desc" class="inline-flex items-center gap-1 text-xs text-[var(--color-primary)]">
                            Ranking completo <ExternalLink class="h-3 w-3" />
                        </Link>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table v-if="sortedSellers.length" class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-4 py-2">Seller</th>
                                <th class="px-4 py-2 text-right">Vendas</th>
                                <th class="px-4 py-2 text-right">Volume</th>
                                <th class="px-4 py-2 text-right">TM</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in sortedSellers" :key="s.tenant_id" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="max-w-[180px] truncate px-4 py-2" :title="s.email">{{ s.nome }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ displayNumber(s.quantidade) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ displayCurrency(s.volume) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-zinc-500">{{ displayCurrency(s.ticket_medio) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-8 text-center text-sm text-zinc-500">Sem vendas no período.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/60">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Produtos em destaque</h3>
                        <p class="text-xs text-zinc-500">+{{ displayNumber(growth.produtos_criados) }} cadastrados no período</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="text-xs" :class="productSort === 'volume' ? 'font-semibold text-[var(--color-primary)]' : 'text-zinc-500'" @click="productSort = 'volume'">Volume</button>
                        <button type="button" class="text-xs" :class="productSort === 'quantidade' ? 'font-semibold text-[var(--color-primary)]' : 'text-zinc-500'" @click="productSort = 'quantidade'">Vendas</button>
                        <Link href="/plataforma/produtos" class="inline-flex items-center gap-1 text-xs text-[var(--color-primary)]">
                            Ver produtos <ExternalLink class="h-3 w-3" />
                        </Link>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table v-if="sortedProducts.length" class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800/80">
                            <tr>
                                <th class="px-4 py-2">Produto</th>
                                <th class="px-4 py-2">Seller</th>
                                <th class="px-4 py-2 text-right">Vendas</th>
                                <th class="px-4 py-2 text-right">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in sortedProducts" :key="p.product_id" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="max-w-[160px] truncate px-4 py-2">{{ p.produto }}</td>
                                <td class="max-w-[120px] truncate px-4 py-2 text-zinc-500">{{ p.seller }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ displayNumber(p.quantidade) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ displayCurrency(p.volume) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="px-4 py-8 text-center text-sm text-zinc-500">Sem produtos vendidos no período.</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
            <div class="mb-3 flex items-center gap-2">
                <AlertTriangle class="h-4 w-4 text-amber-500" />
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Atenção necessária</h3>
            </div>
            <div v-if="alerts.length" class="flex flex-wrap gap-2">
                <Link
                    v-for="alert in alerts"
                    :key="alert.key"
                    :href="alert.href"
                    class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <span class="font-semibold tabular-nums">{{ alert.count }}</span>
                    {{ alert.label }}
                </Link>
            </div>
            <p v-else class="text-sm text-zinc-500">Nenhum alerta operacional no momento.</p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/60">
            <div class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <ShoppingCart class="h-4 w-4 text-zinc-500" />
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Últimas transações</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2">Data</th>
                            <th class="px-4 py-2">Seller</th>
                            <th class="px-4 py-2">Produto</th>
                            <th class="px-4 py-2">Método</th>
                            <th class="px-4 py-2 text-right">Valor</th>
                            <th class="px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in ultimas_transacoes" :key="t.id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="whitespace-nowrap px-4 py-2 text-zinc-600 dark:text-zinc-300">
                                {{ t.created_at ? new Date(t.created_at).toLocaleString('pt-BR') : '—' }}
                            </td>
                            <td class="max-w-[140px] truncate px-4 py-2 text-zinc-700 dark:text-zinc-200" :title="t.email">{{ t.seller_name || '—' }}</td>
                            <td class="max-w-[160px] truncate px-4 py-2 text-zinc-600 dark:text-zinc-400">{{ t.product_name || '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-zinc-500" :title="t.gateway_label || t.gateway || ''">{{ t.payment_method || '—' }}</td>
                            <td class="px-4 py-2 text-right font-medium tabular-nums">{{ formatBRL(t.amount) }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ statusLabels[t.status] || t.status }}</span>
                            </td>
                        </tr>
                        <tr v-if="!ultimas_transacoes.length">
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Nenhuma transação ainda.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
