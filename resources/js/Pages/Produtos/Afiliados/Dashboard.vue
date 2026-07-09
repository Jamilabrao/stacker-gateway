<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AfiliadoTabs from '@/components/afiliados/AfiliadoTabs.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { CircleDollarSign, ShoppingCart, TrendingUp, Clock, Package } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    vendas_recentes: { type: Array, default: () => [] },
    top_produtos: { type: Array, default: () => [] },
});

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Dashboard do afiliado</h1>
            <p class="mt-1 text-sm text-zinc-500">Acompanhe suas comissões e vendas geradas.</p>
        </div>
        <AfiliadoTabs />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AuroraStatCard label="Receita de comissão" :value="formatBRL(stats.receita_comissao)" :icon="CircleDollarSign" />
            <AuroraStatCard label="Total de vendas" :value="String(stats.total_vendas ?? 0)" :icon="ShoppingCart" />
            <AuroraStatCard label="Ticket médio" :value="formatBRL(stats.ticket_medio)" :icon="TrendingUp" />
            <AuroraStatCard label="Comissão pendente" :value="formatBRL(stats.comissao_pendente)" :icon="Clock" />
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white">Vendas recentes</h2>
                <div v-if="!vendas_recentes.length" class="text-sm text-zinc-500">Nenhuma venda ainda.</div>
                <ul v-else class="space-y-3">
                    <li v-for="v in vendas_recentes" :key="v.id" class="flex items-center justify-between text-sm">
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">{{ v.product_name }}</p>
                            <p class="text-xs text-zinc-500">{{ v.customer_name ?? v.customer_email }}</p>
                        </div>
                        <span class="font-medium text-emerald-600">{{ formatBRL(v.commission_net) }}</span>
                    </li>
                </ul>
                <Link href="/produtos/afiliados/vendas" class="mt-4 inline-block text-sm text-[var(--color-primary)] hover:underline">Ver todas</Link>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <h2 class="mb-4 text-sm font-semibold text-zinc-900 dark:text-white">Top produtos</h2>
                <div v-if="!top_produtos.length" class="text-sm text-zinc-500">Sem dados.</div>
                <ul v-else class="space-y-3">
                    <li v-for="p in top_produtos" :key="p.product_id" class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2"><Package class="h-4 w-4 text-zinc-400" />{{ p.product_name }}</span>
                        <span>{{ formatBRL(p.total) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
