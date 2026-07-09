<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import AfiliadoTabs from '@/components/afiliados/AfiliadoTabs.vue';
import AfiliadoVendaDetailSidebar from '@/components/afiliados/AfiliadoVendaDetailSidebar.vue';
import AuroraStatCard from '@/components/aurora/AuroraStatCard.vue';
import { CircleDollarSign, ShoppingCart } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    vendas: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    producers: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
});

const selected = ref(null);
const sidebarOpen = ref(false);

function formatBRL(v) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);
}

function formatDate(v) {
    if (!v) return '—';
    return new Date(v).toLocaleString('pt-BR');
}

function applyFilters(patch = {}) {
    router.get('/produtos/afiliados/vendas', { ...props.filters, ...patch }, { preserveState: true, replace: true });
}

function openDetail(venda) {
    selected.value = venda;
    sidebarOpen.value = true;
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Vendas como afiliado</h1>
            <p class="mt-1 text-sm text-zinc-500">Vendas originadas pelos seus links de afiliado.</p>
        </div>
        <AfiliadoTabs />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AuroraStatCard label="Receita aprovada" :value="formatBRL(stats.comissao_aprovada)" :icon="CircleDollarSign" />
            <AuroraStatCard label="Vendas" :value="String(stats.total_vendas ?? 0)" :icon="ShoppingCart" />
        </div>
        <div class="flex flex-wrap gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <input
                :value="filters.q"
                type="search"
                placeholder="Buscar..."
                class="min-w-[180px] flex-1 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                @change="applyFilters({ q: $event.target.value })"
            />
            <select :value="filters.status" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="applyFilters({ status: $event.target.value })">
                <option v-for="opt in status_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <select :value="filters.product_id" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="applyFilters({ product_id: $event.target.value })">
                <option value="">Todos produtos</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select :value="filters.producer_id" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="applyFilters({ producer_id: $event.target.value })">
                <option value="">Todos produtores</option>
                <option v-for="p in producers" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
        </div>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full text-sm">
                <thead class="bg-zinc-50 text-left text-xs uppercase text-zinc-500 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Produto</th>
                        <th class="px-4 py-3">Cliente</th>
                        <th class="px-4 py-3">Comissão</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="v in vendas.data"
                        :key="v.id"
                        class="cursor-pointer border-t border-zinc-100 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-900/40"
                        @click="openDetail(v)"
                    >
                        <td class="px-4 py-3">{{ formatDate(v.created_at) }}</td>
                        <td class="px-4 py-3">{{ v.product_name }}</td>
                        <td class="px-4 py-3">{{ v.customer_name ?? v.customer_email ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium">{{ formatBRL(v.commission_net) }}</td>
                        <td class="px-4 py-3">{{ v.status_label }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!vendas.data?.length" class="p-8 text-center text-sm text-zinc-500">Nenhuma venda encontrada.</p>
        </div>
        <AfiliadoVendaDetailSidebar :open="sidebarOpen" :venda="selected" @close="sidebarOpen = false" />
    </div>
</template>
