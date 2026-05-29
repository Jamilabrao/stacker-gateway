<script setup>
import { computed } from 'vue';
import { CreditCard } from 'lucide-vue-next';
import AuroraConversionSparkline from '@/components/dashboard/AuroraConversionSparkline.vue';

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
    <div class="aurora-card flex flex-col p-5 lg:col-span-2">
        <h2 class="aurora-fg-muted mb-4 flex items-center gap-2 text-xs font-bold uppercase tracking-widest">
            <CreditCard class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
            {{ paymentMethodsLabel }}
        </h2>

        <div class="flex-1">
            <ul class="space-y-4">
                <li
                    v-for="fp in formas_pagamento"
                    :key="fp.metodo"
                >
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="aurora-fg text-sm font-medium">{{ fp.label }}</span>
                        <span class="aurora-card-value text-sm font-bold">
                            {{ displayCurrency(fp.total) }}
                            <span class="aurora-fg-muted ml-1 text-xs font-normal">({{ displayNumber(fp.quantidade) }})</span>
                        </span>
                    </div>
                    <div class="aurora-progress-track">
                        <div
                            class="aurora-progress-fill transition-all duration-500"
                            :style="{ width: `${percentOfTotal(fp)}%` }"
                        />
                    </div>
                </li>
                <li v-if="!formas_pagamento.length" class="aurora-fg-muted py-4 text-center text-sm">
                    {{ noPaymentsLabel }}
                </li>
            </ul>
        </div>

        <div class="aurora-divider mt-6 border-t pt-5">
            <p class="aurora-fg-muted mb-2 text-[10px] font-bold uppercase tracking-widest">
                {{ conversionLabel }}
            </p>
            <div class="flex items-end justify-between">
                <p class="aurora-card-value text-3xl font-bold leading-none">
                    {{ conversaoFormatted }}
                </p>
                <div class="w-24">
                    <AuroraConversionSparkline
                        :grafico-vendas="grafico_vendas"
                        :values-visible="valuesVisible"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
