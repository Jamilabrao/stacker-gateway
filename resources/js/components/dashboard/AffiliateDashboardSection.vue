<script setup>
import { Link } from '@inertiajs/vue3';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { CircleDollarSign, ShoppingCart } from 'lucide-vue-next';

const props = defineProps({
    stats: { type: Object, default: null },
    recentSales: { type: Array, default: () => [] },
    valuesVisible: { type: Boolean, default: true },
    formatCurrency: { type: Function, required: true },
    formatNumber: { type: Function, required: true },
});

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('pt-BR');
}
</script>

<template>
    <AuroraPageSection v-if="stats">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Vendas como afiliado</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Comissões das vendas que você indicou.</p>
            </div>
            <Link
                href="/vendas?view=affiliate"
                class="text-sm font-medium text-[var(--color-primary)] hover:underline"
            >
                Ver todas
            </Link>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AuroraStatCard
                :icon="ShoppingCart"
                label="Vendas"
                :value="formatNumber(stats.total_vendas ?? 0)"
            />
            <AuroraStatCard
                :icon="CircleDollarSign"
                label="Comissão aprovada"
                :value="formatCurrency(stats.comissao_aprovada ?? 0)"
            />
            <AuroraStatCard
                :icon="CircleDollarSign"
                label="Comissão pendente"
                :value="formatCurrency(stats.comissao_pendente ?? 0)"
            />
            <AuroraStatCard
                :icon="CircleDollarSign"
                label="Ticket médio"
                :value="formatCurrency(stats.ticket_medio ?? 0)"
            />
        </div>

        <div v-if="recentSales.length" class="mt-6 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Produto</th>
                        <th class="px-4 py-3">Comissão</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="sale in recentSales"
                        :key="sale.id"
                        class="border-t border-zinc-100 dark:border-zinc-700"
                    >
                        <td class="px-4 py-3">{{ formatDate(sale.created_at) }}</td>
                        <td class="px-4 py-3">{{ sale.product_name }}</td>
                        <td class="px-4 py-3 font-medium">{{ formatCurrency(sale.commission_net) }}</td>
                        <td class="px-4 py-3">{{ sale.status_label }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuroraPageSection>
</template>
