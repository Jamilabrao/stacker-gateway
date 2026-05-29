<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';

const props = defineProps({
    grafico_vendas: { type: Array, default: () => [] },
    valuesVisible: { type: Boolean, default: true },
});

const series = computed(() => [
    {
        name: 'Vendas',
        data: props.valuesVisible
            ? props.grafico_vendas.map((d) => Number(d.total) || 0)
            : props.grafico_vendas.map(() => 0),
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
        gradient: {
            opacityFrom: 0.35,
            opacityTo: 0.02,
        },
    },
    tooltip: { enabled: false },
}));
</script>

<template>
    <div class="h-[56px] w-full">
        <VueApexCharts
            v-if="grafico_vendas.length"
            type="area"
            height="56"
            :options="options"
            :series="series"
        />
        <div
            v-else
            class="aurora-fg-muted flex h-full items-center justify-center rounded-lg border border-dashed aurora-divider text-[11px]"
        >
            Sem dados
        </div>
    </div>
</template>
