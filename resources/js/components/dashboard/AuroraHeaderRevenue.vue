<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed, inject } from 'vue';

const auroraDashboard = inject('auroraDashboard', null);

const vendasTotais = computed(() => auroraDashboard?.vendas_totais?.value ?? 0);
const graficoVendas = computed(() => auroraDashboard?.grafico_vendas?.value ?? []);
const valuesVisible = computed(() => auroraDashboard?.valuesVisible?.value ?? true);
const formatCurrency = computed(() => auroraDashboard?.formatCurrency?.value ?? ((v) => String(v)));

const displayValue = computed(() =>
    valuesVisible.value ? formatCurrency.value(vendasTotais.value) : '••••••'
);

const series = computed(() => [
    {
        name: 'Faturamento',
        data: valuesVisible.value
            ? graficoVendas.value.map((d) => Number(d.total) || 0)
            : graficoVendas.value.map(() => 0),
    },
]);

const options = computed(() => ({
    chart: {
        type: 'area',
        sparkline: { enabled: true },
        animations: { enabled: true, speed: 400 },
    },
    stroke: { curve: 'smooth', width: 2 },
    colors: ['var(--color-primary)'],
    fill: {
        type: 'gradient',
        gradient: { opacityFrom: 0.3, opacityTo: 0.02 },
    },
    tooltip: { enabled: false },
}));
</script>

<template>
    <div
        v-if="auroraDashboard"
        class="aurora-header-revenue hidden items-center gap-3 px-3 py-2 md:flex"
    >
        <div class="min-w-0">
            <p class="aurora-fg-muted text-[10px] font-semibold uppercase tracking-wider">
                Faturamento
            </p>
            <p class="aurora-fg truncate text-sm font-bold">
                {{ displayValue }}
            </p>
        </div>
        <div class="h-8 w-16 shrink-0">
            <VueApexCharts
                v-if="graficoVendas.length"
                type="area"
                height="32"
                width="64"
                :options="options"
                :series="series"
            />
        </div>
    </div>
</template>
