<script setup>
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import { AlertTriangle } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    disputes: { type: Array, default: () => [] },
    filter_status: { type: String, default: 'open' },
    open_count: { type: Number, default: 0 },
});

const statusTabs = [
    { value: 'open', label: 'Abertas' },
    { value: 'resolved', label: 'Resolvidas' },
    { value: 'all', label: 'Todas' },
];

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function statusLabel(s) {
    const map = {
        open: 'Aberta',
        defense_submitted: 'Defesa enviada',
        resolved_won: 'Ganha',
        resolved_lost: 'Perdida',
        cancelled: 'Cancelada',
    };
    return map[s] ?? s;
}

function setFilter(status) {
    router.get('/vendas/disputas', { status }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Vendas</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Contestações PIX (MED) e defesas junto ao banco.
            </p>
        </div>

        <VendasTabs />

        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Contestações PIX (MED) abertas pelo banco. Envie sua defesa antes do prazo.
            </p>
            <span
                v-if="open_count > 0"
                class="inline-flex items-center gap-1 rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-medium text-orange-900 dark:bg-orange-900/40 dark:text-orange-100"
            >
                <AlertTriangle class="h-3.5 w-3.5" />
                {{ open_count }} aberta(s)
            </span>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in statusTabs"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                :class="
                    filter_status === tab.value
                        ? 'bg-[var(--color-primary)] text-white'
                        : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200'
                "
                @click="setFilter(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div
            v-if="!disputes.length"
            class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/80 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900/40"
        >
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Nenhuma disputa neste filtro.</p>
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600">Pedido</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600">Valor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="d in disputes" :key="d.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3 text-sm">
                            <span class="font-medium">#{{ d.order?.public_reference ?? d.order?.id }}</span>
                            <p class="text-xs text-zinc-500">{{ d.order?.product_name }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ formatBRL(d.amount_cents) }}</td>
                        <td class="px-4 py-3 text-sm">{{ statusLabel(d.status) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a
                                :href="`/vendas/disputas/${d.id}`"
                                class="text-sm font-medium text-[var(--color-primary)] hover:underline"
                            >
                                Ver detalhes
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
