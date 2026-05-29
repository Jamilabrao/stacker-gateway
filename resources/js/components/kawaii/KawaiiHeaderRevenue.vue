<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed, inject } from 'vue';

const kawaiiDashboard = inject('kawaiiDashboard', null);

const vendasTotais = computed(() => kawaiiDashboard?.vendas_totais?.value ?? 0);
const graficoVendas = computed(() => kawaiiDashboard?.grafico_vendas?.value ?? []);
const valuesVisible = computed(() => kawaiiDashboard?.valuesVisible?.value ?? true);
const formatCurrency = computed(() => kawaiiDashboard?.formatCurrency?.value ?? ((v) => String(v)));

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
    colors: ['#22c55e'],
    fill: {
        type: 'gradient',
        gradient: { opacityFrom: 0.35, opacityTo: 0.02 },
    },
    tooltip: { enabled: false },
}));
</script>

<template>
    <div
        v-if="kawaiiDashboard"
        class="kawaii-header-revenue hidden items-center gap-3 rounded-2xl px-3 py-2 md:flex"
    >
        <div class="min-w-0">
            <p class="kawaii-fg-muted text-[10px] font-extrabold uppercase tracking-wider">
                Faturamento
            </p>
            <p class="kawaii-fg truncate text-sm font-extrabold">
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
