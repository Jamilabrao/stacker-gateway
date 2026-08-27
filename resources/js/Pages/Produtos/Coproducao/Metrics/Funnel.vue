<script setup>
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import MetricsNav from '@/components/metrics/MetricsNav.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import { computed } from 'vue';

defineOptions({ layout: LayoutInfoprodutor });
const { pageClass, innerPanelClass } = usePanelThemeClasses();

const props = defineProps({
    period: String,
    date_from: String,
    date_to: String,
    filters: { type: Object, default: () => ({}) },
    funnel: { type: Object, default: () => ({ steps: [] }) },
    summary: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    tab: { type: String, default: 'funnel' },
    base_path: { type: String, default: '/produtos/coproducao/metricas' },
});

const maxValue = computed(() => Math.max(...(props.funnel.steps || []).map((s) => Number(s.value) || 0), 1));
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader title="Coprodutor - Funil" subtitle="Da visita à venda aprovada, com perda entre etapas." />
        <div class="mt-4 space-y-6">
            <ProdutosTabs />
            <MetricsNav :tab="tab" :period="period" :date_from="date_from" :date_to="date_to" :filters="filters" :base-path="base_path" />

            <div :class="[innerPanelClass, 'p-6']">
                <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-300">
                    Conversão final:
                    <strong class="text-zinc-900 dark:text-white">{{ summary.conversion_rate || 0 }}%</strong>
                    (aprovadas ÷ visitantes únicos)
                </p>
                <div class="space-y-4">
                    <div v-for="(step, idx) in (funnel.steps || [])" :key="step.key">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ idx + 1 }}. {{ step.label }}</span>
                            <span class="text-zinc-600 dark:text-zinc-300">
                                {{ step.value }}
                                <span class="text-xs text-zinc-500">({{ step.percent_of_first }}% do topo)</span>
                            </span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                class="h-full rounded-full bg-emerald-500 transition-all"
                                :style="{ width: `${Math.max(4, (Number(step.value) / maxValue) * 100)}%` }"
                            />
                        </div>
                        <p v-if="step.dropoff_percent != null" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                            Queda de {{ step.dropoff_percent }}% em relação à etapa anterior
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
