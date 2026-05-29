<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import {
    CircleDollarSign,
    ShoppingCart,
    CreditCard,
    ShoppingBag,
    RotateCcw,
    Package,
    Users,
    TrendingUp,
    Eye,
    EyeOff,
    XCircle,
    Download,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });
const { t } = useI18n();
const {
    pageClass,
    iconBtn,
    btnSecondary,
    statCard,
    statCardLabel,
    statCardValue,
    tablePanel,
    filterPanelClass,
    innerPanelClass,
    themePrefix,
    isThemedShell,
} = usePanelThemeClasses();

const panelCardClass = innerPanelClass;

const valuesVisible = ref(true);
const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const props = defineProps({
    period: { type: String, default: 'hoje' },
    date_from: { type: String, default: null },
    date_to: { type: String, default: null },
    receita_total: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    total_alunos: { type: Number, default: 0 },
    total_produtos: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    grafico_receita: { type: Array, default: () => [] },
    receita_por_produto: { type: Array, default: () => [] },
    abandonados_visit: { type: Number, default: 0 },
    abandonados_form: { type: Number, default: 0 },
    abandonados_total: { type: Number, default: 0 },
    taxa_conversao: { type: Number, default: 0 },
    abandonados_com_email: { type: Array, default: () => [] },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
});

const periodOptions = [
    { value: 'hoje', label: t('period.today', 'Hoje') },
    { value: 'ontem', label: t('period.yesterday', 'Ontem') },
    { value: '7dias', label: t('period.7days', '7 dias') },
    { value: 'mes', label: t('period.month', 'Mês') },
    { value: 'ano', label: t('period.year', 'Ano') },
    { value: 'total', label: t('period.total', 'Total') },
    { value: 'personalizado', label: t('sales.period.custom', 'Personalizado') },
];

function formatYmd(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function defaultDateFrom() {
    const d = new Date();
    d.setDate(1);
    return formatYmd(d);
}

function defaultDateTo() {
    return formatYmd(new Date());
}

const customFrom = ref(props.date_from || '');
const customTo = ref(props.date_to || '');

watch(
    () => [props.period, props.date_from, props.date_to],
    () => {
        if (props.period === 'personalizado') {
            customFrom.value = props.date_from || '';
            customTo.value = props.date_to || '';
        }
    },
);

function setPeriod(value) {
    if (value === 'personalizado') {
        const from = props.date_from || defaultDateFrom();
        const to = props.date_to || defaultDateTo();
        router.get('/relatorios', { period: value, date_from: from, date_to: to }, { preserveState: false });
        return;
    }
    router.get('/relatorios', { period: value }, { preserveState: false });
}

function applyCustomPeriod() {
    router.get(
        '/relatorios',
        {
            period: 'personalizado',
            date_from: customFrom.value || defaultDateFrom(),
            date_to: customTo.value || defaultDateTo(),
        },
        { preserveState: false },
    );
}

const abandonedExportUrl = computed(() => {
    const p = new URLSearchParams({ period: props.period });
    if (props.period === 'personalizado') {
        if (props.date_from) p.set('date_from', props.date_from);
        if (props.date_to) p.set('date_to', props.date_to);
    }
    return `/relatorios/carrinhos-abandonados/export?${p.toString()}`;
});

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

function formatDate(iso) {
    if (!iso) return '–';
    const d = new Date(iso);
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

const chartSeriesReceita = computed(() => [
    {
        name: 'Receita',
        data: valuesVisible.value ? props.grafico_receita.map((d) => d.total) : props.grafico_receita.map(() => 0),
    },
]);

const chartOptionsReceita = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
    colors: ['var(--color-primary)'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0.2, opacityFrom: 0.4, opacityTo: 0.05 } },
    xaxis: {
        categories: props.grafico_receita.map((d) => {
            const [y, m, day] = (d.data || '').split('-');
            return day && m ? `${day}/${m}` : d.data;
        }),
        labels: { style: { colors: '#71717a' } },
    },
    yaxis: { labels: { style: { colors: '#71717a' }, formatter: (v) => formatBRL(v) } },
    grid: { borderColor: 'var(--chart-grid, #e4e4e7)', strokeDashArray: 4, xaxis: { lines: { show: false } } },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
    },
}));

const chartSeriesProduto = computed(() => [
    {
        name: 'Receita',
        data: valuesVisible.value ? props.receita_por_produto.map((d) => d.total) : props.receita_por_produto.map(() => 0),
    },
]);

const chartOptionsProduto = computed(() => ({
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['var(--color-primary)'],
    dataLabels: { enabled: false },
    plotOptions: { bar: { horizontal: true } },
    xaxis: {
        categories: props.receita_por_produto.map((d) => (d.product_name || 'Produto').slice(0, 35)),
        labels: { style: { colors: '#71717a' }, formatter: (v) => formatBRL(v) },
    },
    yaxis: { labels: { style: { colors: '#71717a' }, maxWidth: 140 } },
    grid: { borderColor: 'var(--chart-grid, #e4e4e7)', strokeDashArray: 4 },
    tooltip: {
        theme: isDarkMode.value ? 'dark' : 'light',
        y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
    },
}));

const formasFiltradas = computed(() => props.formas_pagamento.filter((fp) => fp.total > 0));

const chartSeriesFormas = computed(() =>
    valuesVisible.value ? formasFiltradas.value.map((fp) => fp.total) : formasFiltradas.value.map(() => 0)
);

const chartOptionsFormas = computed(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: formasFiltradas.value.map((fp) => fp.label),
    colors: ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#8b5cf6'].slice(0, formasFiltradas.value.length) || ['#6366f1'],
    dataLabels: { enabled: false },
    legend: { position: 'bottom' },
    tooltip: { theme: isDarkMode.value ? 'dark' : 'light' },
}));
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader
            :title="t('sidebar.reports', 'Relatórios')"
            :subtitle="t('reports.subtitle', 'Analise resultados, receita e indicadores do seu negócio.')"
        />

        <AuroraPageSection>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav
                    :class="[
                        themePrefix
                            ? `${themePrefix}-subnav flex-wrap`
                            : 'flex flex-wrap items-center gap-1',
                    ]"
                    :aria-label="t('dashboard.period', 'Período')"
                >
                    <button
                        v-for="opt in periodOptions"
                        :key="opt.value"
                        type="button"
                        :aria-current="period === opt.value ? 'true' : undefined"
                        :class="[
                            themePrefix
                                ? [`${themePrefix}-subnav-item`, period === opt.value && `${themePrefix}-subnav-item-active`]
                                : 'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            !themePrefix &&
                                (period === opt.value
                                    ? 'bg-[var(--color-primary)] text-white'
                                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'),
                        ]"
                        @click="setPeriod(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </nav>
                <button
                    type="button"
                    :aria-label="valuesVisible ? t('dashboard.hide_values', 'Ocultar valores') : t('dashboard.show_values', 'Mostrar valores')"
                    class="flex h-9 w-9 items-center justify-center rounded-lg transition-colors"
                    :class="iconBtn"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                    <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

        <div
            v-if="period === 'personalizado'"
            class="flex flex-wrap items-end gap-3"
            :class="filterPanelClass"
        >
            <div>
                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('common.from', 'De') }}</label>
                <input
                    v-model="customFrom"
                    type="date"
                    class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('common.to', 'Até') }}</label>
                <input
                    v-model="customTo"
                    type="date"
                    class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <button
                type="button"
                class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
                @click="applyCustomPeriod"
            >
                {{ t('reports.apply_period', 'Aplicar período') }}
            </button>
        </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <AuroraStatCard
                    :icon="CircleDollarSign"
                    :label="t('reports.total_revenue', 'Receita total')"
                    :value="displayCurrency(receita_total)"
                />
                <AuroraStatCard
                    :icon="ShoppingCart"
                    :label="t('sidebar.sales', 'Vendas')"
                    :value="displayNumber(quantidade_vendas)"
                />
                <AuroraStatCard
                    :icon="TrendingUp"
                    :label="t('dashboard.avg_ticket', 'Ticket médio')"
                    :value="displayCurrency(ticket_medio)"
                />
                <AuroraStatCard
                    :icon="Users"
                    :label="t('products.tab_students', 'Alunos')"
                    :value="displayNumber(total_alunos)"
                />
                <AuroraStatCard
                    :icon="Package"
                    :label="t('sidebar.products', 'Produtos')"
                    :value="displayNumber(total_produtos)"
                />
                <div :class="statCard">
                    <div :class="statCardLabel">
                        <XCircle class="h-5 w-5 text-[var(--color-primary)]" />
                        <span>{{ t('reports.abandoned_sales', 'Vendas abandonadas') }}</span>
                    </div>
                    <p :class="statCardValue">
                        {{ displayNumber(abandonados_total) }}
                    </p>
                    <p class="mt-1 text-xs aurora-fg-muted">
                        {{ t('reports.rate', 'Taxa') }}: {{ valuesVisible ? `${taxa_conversao}%` : '—' }} {{ t('reports.conversion', 'conversão') }}
                    </p>
                </div>
            </div>
        </AuroraPageSection>

        <AuroraPageSection>
        <div class="grid gap-4 lg:grid-cols-2">
            <div :class="panelCardClass">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Receita por período</h2>
                <div class="mt-4 min-h-[260px]">
                    <VueApexCharts
                        v-if="grafico_receita.length"
                        type="area"
                        height="260"
                        :options="chartOptionsReceita"
                        :series="chartSeriesReceita"
                    />
                    <p v-else class="flex h-[260px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum dado no período
                    </p>
                </div>
            </div>
            <div :class="panelCardClass">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Receita por produto (top 10)</h2>
                <div class="mt-4 min-h-[260px]">
                    <VueApexCharts
                        v-if="receita_por_produto.length"
                        type="bar"
                        height="260"
                        :options="chartOptionsProduto"
                        :series="chartSeriesProduto"
                    />
                    <p v-else class="flex h-[260px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum dado no período
                    </p>
                </div>
            </div>
        </div>
        </AuroraPageSection>

        <AuroraPageSection>
        <div class="grid gap-4 lg:grid-cols-3">
            <div :class="[panelCardClass, 'lg:col-span-2']">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <CreditCard class="h-4 w-4 text-zinc-500" />
                    Formas de pagamento
                </h2>
                <ul class="mt-4 space-y-3">
                    <li
                        v-for="fp in formas_pagamento"
                        :key="fp.metodo"
                        class="flex items-center justify-between border-b border-zinc-200/60 py-2 last:border-0 dark:border-zinc-700/60"
                    >
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ fp.label }}</span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-white">
                            {{ displayCurrency(fp.total) }}
                            <span class="font-normal text-zinc-500">({{ displayNumber(fp.quantidade) }})</span>
                        </span>
                    </li>
                    <li v-if="!formas_pagamento.length" class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                        Nenhum pagamento no período
                    </li>
                </ul>
            </div>
            <div class="space-y-4">
                <div :class="panelCardClass">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Distribuição</h2>
                    <div class="mt-4 min-h-[160px]">
                        <VueApexCharts
                            v-if="formasFiltradas.length"
                            type="donut"
                            height="180"
                            :options="chartOptionsFormas"
                            :series="chartSeriesFormas"
                        />
                        <p v-else class="flex h-[160px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                            Sem dados
                        </p>
                    </div>
                </div>
                <div :class="panelCardClass">
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <RotateCcw class="h-4 w-4" />
                        <span class="text-sm font-medium">Reembolsos</span>
                    </div>
                    <p class="mt-2 text-lg font-bold text-zinc-900 dark:text-white">{{ displayCurrency(reembolsos_total) }}</p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ displayNumber(reembolsos_count) }} pedido(s)</p>
                </div>
            </div>
        </div>
        </AuroraPageSection>

        <AuroraPageSection flush>
            <div class="flex flex-wrap items-center justify-between gap-3 p-4 pb-0">
                <h2 class="flex items-center gap-2 text-sm font-semibold aurora-fg">
                    <XCircle class="h-4 w-4 text-zinc-500" />
                    Vendas abandonadas com e-mail (para recuperação)
                </h2>
                <a
                    :href="abandonedExportUrl"
                    :class="[btnSecondary, 'shrink-0']"
                >
                    <Download class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ t('reports.export_abandoned_csv', 'Exportar carrinhos abandonados (CSV)') }}
                </a>
            </div>
            <p class="aurora-fg-muted mt-2 px-4 text-xs">
                {{ t('reports.export_abandoned_hint', 'O arquivo segue o período selecionado acima e inclui visitas sem pedido e formulários não concluídos (mesma regra dos totais de abandono).') }}
            </p>
            <div
                class="mt-4 overflow-hidden"
                :class="tablePanel"
            >
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-100/80 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">E-mail</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Nome</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Telefone</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Produto</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Atualizado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr
                            v-for="a in abandonados_com_email"
                            :key="a.id"
                            class="bg-white dark:bg-zinc-800/60"
                        >
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.email }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.name || '–' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.phone || '–' }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ a.product_name }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ formatDate(a.updated_at) }}</td>
                        </tr>
                        <tr v-if="!abandonados_com_email.length" class="bg-white dark:bg-zinc-800/60">
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                Nenhum abandono com e-mail no período
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AuroraPageSection>
    </div>
</template>
