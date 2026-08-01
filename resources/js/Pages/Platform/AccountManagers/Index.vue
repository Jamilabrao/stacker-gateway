<script setup>
import { ref, watch, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { ContactRound, Plus, Search } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    managers: { type: Object, required: true },
    filters: { type: Object, default: () => ({ q: null, status: 'all', per_page: 25 }) },
    ready: { type: Boolean, default: true },
});

const page = usePage();
const searchQ = ref(props.filters?.q ?? '');
const status = ref(props.filters?.status ?? 'all');
const perPage = ref(String(props.filters?.per_page ?? 25));

watch(
    () => props.filters,
    (f) => {
        searchQ.value = f?.q ?? '';
        status.value = f?.status ?? 'all';
        perPage.value = String(f?.per_page ?? 25);
    },
    { deep: true }
);

const rows = computed(() => props.managers?.data ?? []);

function applyFilters() {
    router.get(
        '/plataforma/gerentes-conta',
        {
            q: searchQ.value?.trim() || undefined,
            status: status.value === 'all' ? undefined : status.value,
            per_page: perPage.value,
        },
        { preserveState: true, replace: true }
    );
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                    <ContactRound class="h-6 w-6 text-[var(--color-primary)]" />
                    Gerentes de Conta
                </h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Cadastre gerentes, vincule infoprodutores e gerencie transferências de carteira.
                </p>
            </div>
            <Link href="/plataforma/gerentes-conta/criar">
                <Button type="button" class="inline-flex items-center gap-2">
                    <Plus class="h-4 w-4" />
                    Novo gerente
                </Button>
            </Link>
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

        <p
            v-if="!ready"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Execute as migrações do banco para usar este módulo.
        </p>

        <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center" @submit.prevent="applyFilters">
            <div class="relative min-w-[200px] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="Nome, e-mail ou telefone"
                    class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
            </div>
            <select
                v-model="status"
                class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
            >
                <option value="all">Todos os status</option>
                <option value="active">Ativos</option>
                <option value="inactive">Inativos</option>
            </select>
            <select
                v-model="perPage"
                class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
            >
                <option value="25">25 / página</option>
                <option value="50">50 / página</option>
                <option value="100">100 / página</option>
            </select>
            <Button type="submit">Filtrar</Button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <div class="overflow-x-auto">
                <table class="min-w-[900px] w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                        <tr>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600">Gerente</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600">Contato</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600">Status</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-zinc-600">Carteira</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        <tr v-for="m in rows" :key="m.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="m.avatar_url"
                                        :src="m.avatar_url"
                                        alt=""
                                        class="h-10 w-10 rounded-full object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-200 text-sm font-semibold text-zinc-600 dark:bg-zinc-700 dark:text-zinc-200"
                                    >
                                        {{ (m.name || '?').charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ m.name }}</div>
                                        <div class="text-xs text-zinc-500">#{{ m.id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-zinc-800 dark:text-zinc-200">{{ m.email }}</div>
                                <div class="text-xs text-zinc-500">{{ m.phone_display || m.phone || '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        m.is_active
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                                            : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200'
                                    "
                                >
                                    {{ m.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ m.merchants_count ?? 0 }}</td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    :href="`/plataforma/gerentes-conta/${m.id}`"
                                    class="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                >
                                    Abrir
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!rows.length" class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-500">
            Nenhum gerente encontrado.
        </div>

        <nav
            v-if="(managers?.links?.length ?? 0) > 3"
            class="flex flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in managers.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                :class="[
                    'relative inline-flex min-h-[2.25rem] items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    link.active
                        ? 'z-10 bg-[var(--color-primary)] text-white'
                        : link.url
                          ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                          : 'cursor-not-allowed text-zinc-400',
                ]"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>
    </div>
</template>
