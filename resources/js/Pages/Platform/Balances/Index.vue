<script setup>
import { ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import WalletAdjustForm from '@/components/platform/WalletAdjustForm.vue';
import Button from '@/components/ui/Button.vue';
import { X } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    merchants: { type: Object, required: true },
    filters: {
        type: Object,
        default: () => ({ q: '', has_balance: true }),
    },
});

const searchQ = ref(props.filters.q || '');
const adjustUserId = ref(null);
const adjustUserName = ref('');

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function amountClass(n) {
    const v = Number(n) || 0;
    if (v < 0) return 'text-red-600 dark:text-red-400 font-medium';
    if (v > 0) return 'text-emerald-700 dark:text-emerald-300';
    return 'text-zinc-600 dark:text-zinc-400';
}

function applyFilters() {
    router.get(
        '/plataforma/saldo',
        {
            q: searchQ.value || undefined,
            has_balance: props.filters.has_balance ? '1' : '0',
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function toggleHasBalance() {
    router.get(
        '/plataforma/saldo',
        {
            q: props.filters.q || undefined,
            has_balance: props.filters.has_balance ? '0' : '1',
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function openAdjust(m) {
    adjustUserId.value = m.id;
    adjustUserName.value = m.name;
}

function closeAdjust() {
    adjustUserId.value = null;
    adjustUserName.value = '';
}

const adjustRedirectTo = '/plataforma/saldo';

watch(
    () => page.props.flash?.success,
    (msg) => {
        if (msg && adjustUserId.value) {
            closeAdjust();
        }
    }
);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Saldo</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Infoprodutores com saldo na carteira. Ajustes manuais ficam registrados no extrato.
            </p>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <form class="flex flex-1 flex-wrap items-end gap-2" @submit.prevent="applyFilters">
                <div class="min-w-[200px] flex-1">
                    <label class="mb-1 block text-xs font-medium text-zinc-500">Buscar</label>
                    <input
                        v-model="searchQ"
                        type="search"
                        placeholder="Nome ou e-mail"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    />
                </div>
                <Button type="submit" variant="secondary">Filtrar</Button>
            </form>
            <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <input
                    type="checkbox"
                    class="h-4 w-4 rounded"
                    :checked="filters.has_balance"
                    @change="toggleHasBalance"
                />
                Somente com saldo ≠ 0
            </label>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/60">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3">Infoprodutor</th>
                        <th class="px-4 py-3 text-right">Disponível</th>
                        <th class="px-4 py-3 text-right">Pendente</th>
                        <th class="px-4 py-3 text-right">MED</th>
                        <th class="px-4 py-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!merchants.data?.length">
                        <td colspan="5" class="px-4 py-10 text-center text-zinc-500">Nenhum infoprodutor encontrado.</td>
                    </tr>
                    <tr
                        v-for="m in merchants.data"
                        :key="m.id"
                        class="border-b border-zinc-100 dark:border-zinc-800"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ m.name }}</p>
                            <p class="text-xs text-zinc-500">{{ m.email }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums" :class="amountClass(m.available_total)">
                            {{ formatBRL(m.available_total) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-400">
                            {{ formatBRL(m.pending_total) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-amber-700 dark:text-amber-300">
                            {{ formatBRL(m.med_total) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <Link
                                    :href="`/plataforma/usuarios/${m.id}`"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-[var(--color-primary)] hover:bg-[var(--color-primary)]/10"
                                >
                                    Abrir
                                </Link>
                                <button
                                    type="button"
                                    class="rounded-lg px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    @click="openAdjust(m)"
                                >
                                    Ajustar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="merchants.links?.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in merchants.links"
                :key="i"
                :href="link.url || '#'"
                class="rounded-lg px-3 py-1.5 text-sm"
                :class="
                    link.active
                        ? 'bg-[var(--color-primary)] text-white'
                        : link.url
                          ? 'text-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-800'
                          : 'pointer-events-none text-zinc-300'
                "
                v-text="htmlToText(link.label)"
            />
        </div>

        <div
            v-if="adjustUserId"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeAdjust"
        >
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Ajustar saldo</h2>
                        <p class="text-sm text-zinc-500">{{ adjustUserName }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        @click="closeAdjust"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <WalletAdjustForm :user-id="adjustUserId" :redirect-to="adjustRedirectTo" compact />
            </div>
        </div>
    </div>
</template>
