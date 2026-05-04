<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Search, Package } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    products: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ q: null, filter: 'all' }),
    },
});

const page = usePage();
const searchQ = ref(props.filters?.q ?? '');
const activeFilter = ref(props.filters?.filter ?? 'all');

watch(
    () => props.filters,
    (f) => {
        searchQ.value = f?.q ?? '';
        activeFilter.value = f?.filter ?? 'all';
    },
    { deep: true }
);

const filterChips = [
    { value: 'all', label: 'Todos' },
    { value: 'purchaseable', label: 'À venda' },
    { value: 'blocked', label: 'Bloqueados' },
];

function applyFilters() {
    const q = searchQ.value?.trim() || undefined;
    router.get(
        '/plataforma/produtos',
        { q, filter: activeFilter.value === 'all' ? undefined : activeFilter.value },
        { preserveState: true, replace: true }
    );
}

function selectFilter(value) {
    activeFilter.value = value;
    applyFilters();
}

function setProductBlocked(product, blocked) {
    const verb = blocked ? 'bloquear' : 'desbloquear';
    if (!confirm(`Confirma ${verb} o produto "${product.name}"?`)) return;
    router.post(
        `/plataforma/produtos/${product.id}/bloqueio`,
        { admin_blocked: blocked },
        { preserveScroll: true }
    );
}

const productRows = computed(() => props.products?.data ?? []);

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function typeLabel(t) {
    const m = {
        aplicativo: 'Aplicativo',
        area_membros: 'Área de membros',
        area_membros_externa: 'Área externa',
        link: 'Link',
        link_pagamento: 'Link pagamento',
    };
    return m[t] || t || '—';
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                    <Package class="h-6 w-6 text-[var(--color-primary)]" aria-hidden="true" />
                    Produtos
                </h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Todos os produtos dos infoprodutores. Bloqueie para impedir checkout e vendas via API (área do aluno e pedidos antigos não são apagados).
                </p>
            </div>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>

        <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center" @submit.prevent="applyFilters">
            <div class="relative min-w-[200px] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="Nome ou slug de checkout"
                    class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <button
                type="submit"
                class="rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
            >
                Pesquisar
            </button>
        </form>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in filterChips"
                :key="chip.value"
                type="button"
                :class="[
                    'rounded-lg border px-3 py-2 text-sm font-medium transition',
                    activeFilter === chip.value
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                        : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-200',
                ]"
                @click="selectFilter(chip.value)"
            >
                {{ chip.label }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="overflow-x-auto">
                <table class="min-w-[960px] w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Produto</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Infoprodutor</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Tipo</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Preço</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Estado</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        <tr v-for="p in productRows" :key="p.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ p.name }}</div>
                                <div class="mt-0.5 font-mono text-xs text-zinc-500">/c/{{ p.checkout_slug }}</div>
                                <div class="mt-0.5 text-xs text-zinc-400">ID {{ p.id }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-zinc-900 dark:text-white">{{ p.infoprodutor_name }}</div>
                                <div class="text-xs text-zinc-500">{{ p.infoprodutor_email || '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-700 dark:text-zinc-300">{{ typeLabel(p.type) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-zinc-800 dark:text-zinc-200">
                                {{ formatBRL(p.price) }}
                                <span class="text-xs text-zinc-500">{{ p.currency || 'BRL' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                    <span
                                        v-if="p.admin_blocked"
                                        class="inline-flex w-fit rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950/50 dark:text-red-200"
                                    >
                                        Bloqueado plataforma
                                    </span>
                                    <span
                                        v-else-if="!p.is_active"
                                        class="inline-flex w-fit rounded-full bg-zinc-200 px-2 py-0.5 text-xs font-medium text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200"
                                    >
                                        Inativo (vendedor)
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex w-fit rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
                                    >
                                        Ativo
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Button
                                        v-if="!p.admin_blocked"
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        @click="setProductBlocked(p, true)"
                                    >
                                        Bloquear
                                    </Button>
                                    <Button v-else type="button" size="sm" @click="setProductBlocked(p, false)">Desbloquear</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!productRows.length" class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhum produto encontrado.
        </div>

        <nav
            v-if="(products?.links?.length ?? 0) > 3"
            class="flex flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in products.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url"
                :class="[
                    'relative inline-flex min-h-[2.25rem] items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    link.active
                        ? 'z-10 bg-[var(--color-primary)] text-white'
                        : link.url
                          ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                          : 'cursor-not-allowed text-zinc-400 dark:text-zinc-500',
                ]"
                v-html="link.label"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>
    </div>
</template>
