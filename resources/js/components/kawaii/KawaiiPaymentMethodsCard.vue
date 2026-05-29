<script setup>
import { computed } from 'vue';
import { CreditCard } from 'lucide-vue-next';
import KawaiiConversionSparkline from '@/components/kawaii/KawaiiConversionSparkline.vue';

const props = defineProps({
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    valuesVisible: { type: Boolean, required: true },
    conversionLabel: { type: String, required: true },
    paymentMethodsLabel: { type: String, required: true },
    noPaymentsLabel: { type: String, required: true },
    grafico_vendas: { type: Array, default: () => [] },
    displayCurrency: { type: Function, required: true },
    displayNumber: { type: Function, required: true },
});

const totalPeriodo = computed(() =>
    props.formas_pagamento.reduce((sum, fp) => sum + (Number(fp.total) || 0), 0)
);

function percentOfTotal(fp) {
    if (!totalPeriodo.value) return 0;
    return Math.round((Number(fp.total) / totalPeriodo.value) * 100);
}

const conversaoFormatted = computed(() =>
    props.valuesVisible ? `${props.taxa_conversao}%` : '—'
);
</script>

<template>
    <div class="kawaii-card flex flex-col p-5 lg:col-span-2">
        <h2 class="kawaii-fg-muted mb-4 flex items-center gap-2 text-xs font-extrabold uppercase tracking-widest">
            <CreditCard class="h-4 w-4 text-[var(--kawaii-accent-text)]" aria-hidden="true" />
            {{ paymentMethodsLabel }}
        </h2>

        <div class="flex-1">
            <ul class="space-y-4">
                <li v-for="fp in formas_pagamento" :key="fp.metodo">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="kawaii-fg text-sm font-bold">{{ fp.label }}</span>
                        <span class="kawaii-metric-value text-sm">
                            {{ displayCurrency(fp.total) }}
                            <span class="kawaii-fg-muted ml-1 text-xs font-semibold">({{ displayNumber(fp.quantidade) }})</span>
                        </span>
                    </div>
                    <div class="kawaii-progress-track">
                        <div
                            class="kawaii-progress-fill transition-all duration-500"
                            :style="{ width: `${percentOfTotal(fp)}%` }"
                        />
                    </div>
                </li>
                <li v-if="!formas_pagamento.length" class="kawaii-fg-muted py-4 text-center text-sm font-semibold">
                    {{ noPaymentsLabel }}
                </li>
            </ul>
        </div>

        <div class="kawaii-divider mt-6 border-t pt-5">
            <p class="kawaii-fg-muted mb-2 text-[10px] font-extrabold uppercase tracking-widest">
                {{ conversionLabel }}
            </p>
            <div class="flex items-end justify-between gap-4">
                <p class="kawaii-metric-value text-3xl">{{ conversaoFormatted }}</p>
                <div class="w-28 shrink-0">
                    <KawaiiConversionSparkline
                        :grafico-vendas="grafico_vendas"
                        :values-visible="valuesVisible"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
