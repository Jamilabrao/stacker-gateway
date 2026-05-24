<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    disputes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const filterStatus = ref(props.filters?.status ?? 'open');
const rows = () => props.disputes?.data ?? [];

watch(
    () => props.filters,
    (f) => {
        filterStatus.value = f?.status ?? 'open';
    },
    { deep: true }
);

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function setFilter(status) {
    router.get('/plataforma/disputas', { status }, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Disputas MED PIX de todos os infoprodutores (CajuPay).</p>

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
                :class="filterStatus === tab.value ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 dark:bg-zinc-800'"
                @click="setFilter(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/60">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Infoprodutor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Pedido</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Valor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="d in rows()" :key="d.id">
                        <td class="px-4 py-3 text-sm">#{{ d.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.tenant?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">#{{ d.order?.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ formatBRL(d.amount_cents) }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.status }}</td>
                        <td class="px-4 py-3 text-right">
                            <a :href="`/plataforma/disputas/${d.id}`" class="text-sm text-[var(--color-primary)] hover:underline">Ver</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="(disputes?.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-2">
            <a
                v-for="link in disputes.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                class="rounded-lg px-3 py-2 text-sm"
                :class="link.active ? 'bg-[var(--color-primary)] text-white' : ''"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>
    </div>
</template>
