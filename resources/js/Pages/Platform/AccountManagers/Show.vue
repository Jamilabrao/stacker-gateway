<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    manager: { type: Object, required: true },
    merchants: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    active_managers: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const page = usePage();
const searchQ = ref(props.filters?.q ?? '');
const perPage = ref(String(props.filters?.per_page ?? 25));
const selectedIds = ref([]);
const transferTarget = ref(props.active_managers?.[0]?.id ? String(props.active_managers[0].id) : '');
const deactivateAction = ref('keep');
const deactivateTarget = ref(transferTarget.value);

watch(
    () => props.filters,
    (f) => {
        searchQ.value = f?.q ?? '';
        perPage.value = String(f?.per_page ?? 25);
    },
    { deep: true }
);

const rows = computed(() => props.merchants?.data ?? []);
const allSelected = computed(
    () => rows.value.length > 0 && rows.value.every((r) => selectedIds.value.includes(r.id))
);

function toggleAll() {
    if (allSelected.value) selectedIds.value = [];
    else selectedIds.value = rows.value.map((r) => r.id);
}

function toggleOne(id) {
    if (selectedIds.value.includes(id)) selectedIds.value = selectedIds.value.filter((x) => x !== id);
    else selectedIds.value = [...selectedIds.value, id];
}

function applyFilters() {
    router.get(
        `/plataforma/gerentes-conta/${props.manager.id}`,
        { q: searchQ.value?.trim() || undefined, per_page: perPage.value },
        { preserveState: true, replace: true }
    );
}

function transfer(all) {
    const target = Number(transferTarget.value);
    if (!target) {
        alert('Selecione o gerente de destino.');
        return;
    }
    const count = all ? props.manager.merchants_count : selectedIds.value.length;
    if (!count) {
        alert('Nenhum infoprodutor para transferir.');
        return;
    }
    const dest = props.active_managers.find((m) => m.id === target);
    if (
        !confirm(
            `Você está prestes a transferir ${count} infoprodutores de ${props.manager.name} para ${dest?.name || 'destino'}. Deseja continuar?`
        )
    ) {
        return;
    }
    router.post(
        `/plataforma/gerentes-conta/${props.manager.id}/transferir`,
        {
            target_manager_id: target,
            transfer_all: all,
            merchant_ids: all ? [] : selectedIds.value,
        },
        { preserveScroll: true }
    );
}

function setActive(active) {
    if (active) {
        router.post(`/plataforma/gerentes-conta/${props.manager.id}/ativacao`, { is_active: true }, { preserveScroll: true });
        return;
    }
    if ((props.manager.merchants_count || 0) > 0) {
        if (deactivateAction.value === 'transfer' && !deactivateTarget.value) {
            alert('Selecione o gerente de destino.');
            return;
        }
        if (
            !confirm(
                `Este gerente possui ${props.manager.merchants_count} infoprodutores vinculados. Confirmar desativação?`
            )
        ) {
            return;
        }
    }
    router.post(
        `/plataforma/gerentes-conta/${props.manager.id}/ativacao`,
        {
            is_active: false,
            deactivate_action: (props.manager.merchants_count || 0) > 0 ? deactivateAction.value : 'keep',
            target_manager_id: deactivateTarget.value || undefined,
            distribute_manager_ids: props.active_managers.map((m) => m.id),
            distribute_mode: 'least_load',
        },
        { preserveScroll: true }
    );
}

function destroyManager() {
    if (!confirm(`Excluir permanentemente "${props.manager.name}"?`)) return;
    router.delete(`/plataforma/gerentes-conta/${props.manager.id}`);
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <Link href="/plataforma/gerentes-conta" class="text-sm text-[var(--color-primary)] hover:underline">← Gerentes</Link>
            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-center gap-4">
                    <img
                        v-if="manager.avatar_url"
                        :src="manager.avatar_url"
                        alt=""
                        class="h-16 w-16 rounded-full object-cover"
                    />
                    <div
                        v-else
                        class="flex h-16 w-16 items-center justify-center rounded-full bg-zinc-200 text-xl font-semibold dark:bg-zinc-700"
                    >
                        {{ (manager.name || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div>
                        <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ manager.name }}</h1>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ manager.email }} · {{ manager.phone_display }}</p>
                        <p class="mt-1 text-sm">Carteira: <strong>{{ manager.merchants_count }}</strong> infoprodutores</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="`/plataforma/gerentes-conta/${manager.id}/editar`">
                        <Button type="button" variant="secondary">Editar</Button>
                    </Link>
                    <Button v-if="!manager.is_active" type="button" @click="setActive(true)">Ativar</Button>
                    <Button v-else type="button" variant="secondary" @click="setActive(false)">Desativar</Button>
                    <Button type="button" variant="secondary" class="!text-red-700" @click="destroyManager">Excluir</Button>
                </div>
            </div>
        </div>

        <p v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ page.props.flash.success }}</p>
        <p v-if="page.props.flash?.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ page.props.flash.error }}</p>

        <div
            v-if="manager.is_active && manager.merchants_count > 0 && active_managers.length"
            class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        >
            <h2 class="font-semibold text-zinc-900 dark:text-white">Transferir carteira</h2>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1 block text-xs font-medium text-zinc-500">Destino</label>
                    <select v-model="transferTarget" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                        <option v-for="m in active_managers" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
                    </select>
                </div>
                <Button type="button" variant="secondary" :disabled="!selectedIds.length" @click="transfer(false)">
                    Transferir selecionados ({{ selectedIds.length }})
                </Button>
                <Button type="button" @click="transfer(true)">Transferir todos</Button>
            </div>
        </div>

        <div
            v-if="manager.is_active && manager.merchants_count > 0"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm dark:border-amber-900/50 dark:bg-amber-950/30"
        >
            <p class="font-medium">Ao desativar, escolha o destino dos vínculos:</p>
            <div class="mt-2 space-y-2">
                <label class="flex items-center gap-2"><input v-model="deactivateAction" type="radio" value="keep" /> Manter vínculos temporariamente</label>
                <label class="flex items-center gap-2"><input v-model="deactivateAction" type="radio" value="transfer" /> Transferir todos para outro gerente</label>
                <label class="flex items-center gap-2"><input v-model="deactivateAction" type="radio" value="distribute" /> Distribuir entre gerentes ativos</label>
            </div>
            <select
                v-if="deactivateAction === 'transfer'"
                v-model="deactivateTarget"
                class="mt-2 w-full max-w-sm rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
            >
                <option v-for="m in active_managers" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
            </select>
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="applyFilters">
            <input v-model="searchQ" type="search" placeholder="Buscar infoprodutor" class="min-w-[200px] flex-1 rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" />
            <select v-model="perPage" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <Button type="submit" variant="secondary">Filtrar</Button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-3 py-2"><input type="checkbox" :checked="allSelected" @change="toggleAll" /></th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase">Infoprodutor</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-3 py-2"><input type="checkbox" :checked="selectedIds.includes(r.id)" @change="toggleOne(r.id)" /></td>
                        <td class="px-3 py-2">
                            <Link :href="`/plataforma/usuarios/${r.id}`" class="font-medium text-[var(--color-primary)] hover:underline">{{ r.name }}</Link>
                            <div class="text-xs text-zinc-500">{{ r.email }}</div>
                        </td>
                        <td class="px-3 py-2">{{ r.account_status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="(merchants?.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-2">
            <a
                v-for="link in merchants.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                class="rounded-lg px-3 py-2 text-sm"
                :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>

        <div v-if="history.length" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold">Histórico recente</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li v-for="h in history" :key="h.id" class="border-b border-zinc-100 pb-2 dark:border-zinc-800">
                    <span class="font-medium">{{ h.merchant?.name || '—' }}</span>
                    · {{ h.source }} · {{ h.assigned_at }}
                    <span v-if="h.reason" class="text-zinc-500"> — {{ h.reason }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>
