<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    disputes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const filterStatus = ref(props.filters?.status ?? 'open');
const filterParty = ref(props.filters?.party ?? 'platform');
const rows = () => props.disputes?.data ?? [];

watch(
    () => props.filters,
    (f) => {
        filterStatus.value = f?.status ?? 'open';
        filterParty.value = f?.party ?? 'platform';
    },
    { deep: true }
);

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function partyLabel(party) {
    return party === 'platform' ? 'Plataforma' : 'API PIX';
}

function applyFilters() {
    router.get(
        '/plataforma/disputas',
        { status: filterStatus.value, party: filterParty.value },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function setStatus(status) {
    filterStatus.value = status;
    applyFilters();
}

function setParty(party) {
    filterParty.value = party;
    applyFilters();
}
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Gestão de disputas MED: checkout e MED Zero (plataforma) vs API PIX REST (infoprodutor).
        </p>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in [
                    { value: 'platform', label: 'Plataforma' },
                    { value: 'tenant', label: 'API PIX' },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium"
                :class="filterParty === tab.value ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 dark:bg-zinc-800'"
                @click="setParty(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in [
                    { value: 'open', label: 'Abertas' },
                    { value: 'resolved', label: 'Resolvidas' },
                    { value: 'all', label: 'Todas' },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium"
                :class="filterStatus === tab.value ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800'"
                @click="setStatus(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/60">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Responsável</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Infoprodutor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Pedido</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Origem</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Valor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="d in rows()" :key="d.id">
                        <td class="px-4 py-3 text-sm">#{{ d.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ partyLabel(d.responsible_party) }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.tenant?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">#{{ d.order?.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.order_origin ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">{{ formatBRL(d.amount_cents) }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.status }}</td>
                        <td class="px-4 py-3 text-right">
                            <a :href="`/plataforma/disputas/${d.id}`" class="text-sm text-[var(--color-primary)] hover:underline">Ver</a>
                        </td>
                    </tr>
                    <tr v-if="rows().length === 0">
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500">Nenhuma disputa encontrada.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
