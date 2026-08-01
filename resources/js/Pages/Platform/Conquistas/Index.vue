<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Upload, Trash2, Save, Plus, Search } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    tab: { type: String, default: 'conquistas' },
    achievements: { type: Array, default: () => [] },
    ranking: { type: Object, default: () => ({ data: [], links: [], meta: {}, filters: {} }) },
    rewards: { type: Object, default: () => ({ data: [], meta: {} }) },
    metric_types: { type: Array, default: () => [] },
    reward_statuses: { type: Array, default: () => [] },
    account_managers: { type: Array, default: () => [] },
});

const items = ref((props.achievements || []).map((a) => ({ ...a })));
const savingId = ref(null);
const deletingId = ref(null);
const uploadingBadgeId = ref(null);
const uploadingRewardId = ref(null);
const creating = ref(false);
const error = ref('');

watch(
    () => props.achievements,
    (list) => {
        items.value = (list || []).map((a) => ({ ...a }));
    },
    { deep: true }
);

const createForm = reactive({
    slug: '',
    name: '',
    description: '',
    metric_type: 'revenue',
    threshold: 0,
    image: '',
    image_path: '',
    sort_order: 0,
    is_active: true,
    reward_name: '',
    reward_description: '',
    reward_image: '',
    reward_image_path: '',
    reward_internal_notes: '',
});

const rankFilters = ref({
    q: props.ranking?.filters?.q ?? '',
    metric_type: props.ranking?.filters?.metric_type ?? 'revenue',
    achievement_id: props.ranking?.filters?.achievement_id ? String(props.ranking.filters.achievement_id) : '',
    account_status: props.ranking?.filters?.account_status ?? '',
    account_manager_id: props.ranking?.filters?.account_manager_id ? String(props.ranking.filters.account_manager_id) : '',
    reward_status: props.ranking?.filters?.reward_status ?? '',
    period_from: props.ranking?.filters?.period_from ?? '',
    period_to: props.ranking?.filters?.period_to ?? '',
    per_page: String(props.ranking?.filters?.per_page ?? 25),
    sort: props.ranking?.filters?.sort ?? 'current_value',
    direction: props.ranking?.filters?.direction ?? 'desc',
});

const rewardsStatusFilter = ref('');

watch(
    () => props.ranking?.filters,
    (f) => {
        if (!f) return;
        rankFilters.value = {
            q: f.q ?? '',
            metric_type: f.metric_type ?? 'revenue',
            achievement_id: f.achievement_id ? String(f.achievement_id) : '',
            account_status: f.account_status ?? '',
            account_manager_id: f.account_manager_id ? String(f.account_manager_id) : '',
            reward_status: f.reward_status ?? '',
            period_from: f.period_from ?? '',
            period_to: f.period_to ?? '',
            per_page: String(f.per_page ?? 25),
            sort: f.sort ?? 'current_value',
            direction: f.direction ?? 'desc',
        };
    },
    { deep: true }
);

const rankRows = computed(() => props.ranking?.data ?? []);
const rewardRows = computed(() => props.rewards?.data ?? []);

const rankRangeLabel = computed(() => {
    const m = props.ranking?.meta;
    if (!m?.total) return null;
    return `Exibindo ${m.from ?? 0}–${m.to ?? 0} de ${m.total}`;
});

function switchTab(tab) {
    router.get('/plataforma/conquistas', { tab }, { preserveState: false, replace: true });
}

function normalizeSlug(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/--+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function onNameInputForCreate() {
    if (!createForm.slug) {
        createForm.slug = normalizeSlug(createForm.name);
    }
}

function imageFieldForSave(item, kind = 'badge') {
    if (kind === 'reward') {
        return item.reward_image_path || item.reward_image || null;
    }
    return item.image_path || item.image || null;
}

function achievementPayload(item) {
    return {
        slug: normalizeSlug(item.slug),
        name: item.name,
        description: item.description || null,
        metric_type: item.metric_type || 'revenue',
        threshold: Number(item.threshold) || 0,
        image: imageFieldForSave(item, 'badge'),
        sort_order: Number(item.sort_order) || 0,
        is_active: !!item.is_active,
        reward_name: item.reward_name || null,
        reward_description: item.reward_description || null,
        reward_image: imageFieldForSave(item, 'reward'),
        reward_internal_notes: item.reward_internal_notes || null,
    };
}

async function createAchievement() {
    creating.value = true;
    error.value = '';
    try {
        const payload = achievementPayload({
            ...createForm,
            slug: normalizeSlug(createForm.slug),
        });
        await window.axios.post('/plataforma/conquistas', payload);
        await router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao criar conquista.';
    } finally {
        creating.value = false;
    }
}

async function saveItem(item) {
    savingId.value = item.id;
    error.value = '';
    try {
        await window.axios.put(`/plataforma/conquistas/${item.id}`, achievementPayload(item));
        await router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao salvar conquista.';
    } finally {
        savingId.value = null;
    }
}

async function deleteItem(item) {
    if (!window.confirm(`Remover a conquista "${item.name}"?`)) return;
    deletingId.value = item.id;
    error.value = '';
    try {
        await window.axios.delete(`/plataforma/conquistas/${item.id}`);
        items.value = items.value.filter((x) => x.id !== item.id);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao remover conquista.';
    } finally {
        deletingId.value = null;
    }
}

async function onImageChange(event, item, kind = 'badge') {
    const file = event.target?.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (kind === 'reward') {
        uploadingRewardId.value = item.id;
    } else {
        uploadingBadgeId.value = item.id;
    }
    error.value = '';

    const fd = new FormData();
    fd.append('file', file);
    if (kind === 'reward') {
        fd.append('kind', 'reward');
    }

    try {
        const res = await window.axios.post(`/plataforma/conquistas/${item.id}/image`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (kind === 'reward') {
            item.reward_image = res.data?.url || item.reward_image;
            item.reward_image_path = res.data?.path || item.reward_image_path;
        } else {
            item.image = res.data?.url || item.image;
            item.image_path = res.data?.path || item.image_path;
        }
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao enviar imagem.';
    } finally {
        uploadingBadgeId.value = null;
        uploadingRewardId.value = null;
    }
}

function rankVisitParams(extra = {}) {
    return {
        tab: 'relatorio',
        q: rankFilters.value.q?.trim() || undefined,
        metric_type: rankFilters.value.metric_type || undefined,
        achievement_id: rankFilters.value.achievement_id ? Number(rankFilters.value.achievement_id) : undefined,
        account_status: rankFilters.value.account_status || undefined,
        account_manager_id: rankFilters.value.account_manager_id ? Number(rankFilters.value.account_manager_id) : undefined,
        reward_status: rankFilters.value.reward_status || undefined,
        period_from: rankFilters.value.period_from || undefined,
        period_to: rankFilters.value.period_to || undefined,
        per_page: Number(rankFilters.value.per_page) || 25,
        sort: rankFilters.value.sort,
        direction: rankFilters.value.direction,
        page: 1,
        ...extra,
    };
}

function applyRankFilters() {
    router.get('/plataforma/conquistas', rankVisitParams(), { preserveState: false, replace: true });
}

function changeRankSort(field) {
    if (rankFilters.value.sort === field) {
        rankFilters.value.direction = rankFilters.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        rankFilters.value.sort = field;
        rankFilters.value.direction = field === 'name' ? 'asc' : 'desc';
    }
    applyRankFilters();
}

function applyRewardsFilter() {
    router.get(
        '/plataforma/conquistas',
        {
            tab: 'premiacoes',
            reward_status: rewardsStatusFilter.value || undefined,
        },
        { preserveState: false, replace: true }
    );
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('pt-BR');
}

function accountStatusLabel(s) {
    const map = {
        approved: 'Aprovado',
        pending: 'Pendente',
        rejected: 'Rejeitado',
        suspended: 'Suspenso',
        blocked: 'Bloqueado',
    };
    return map[s] || s || '—';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Conquistas</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Metas de vendas, relatório de progresso e premiações físicas dos infoprodutores.
            </p>
        </div>

        <nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700" aria-label="Abas de conquistas">
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    tab === 'conquistas'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('conquistas')"
            >
                Conquistas
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    tab === 'relatorio'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('relatorio')"
            >
                Relatório
            </button>
            <button
                type="button"
                class="rounded-t-lg px-4 py-2.5 text-sm font-medium transition"
                :class="
                    tab === 'premiacoes'
                        ? 'border-b-2 border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'
                "
                @click="switchTab('premiacoes')"
            >
                Premiações
            </button>
        </nav>

        <p
            v-if="error"
            class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ error }}
        </p>

        <!-- Tab: Conquistas -->
        <template v-if="tab === 'conquistas'">
            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Nova conquista</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <input
                        v-model="createForm.name"
                        type="text"
                        placeholder="Nome"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        @input="onNameInputForCreate"
                    />
                    <input
                        v-model="createForm.slug"
                        type="text"
                        placeholder="slug"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <select
                        v-model="createForm.metric_type"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    >
                        <option v-for="mt in metric_types" :key="mt.value" :value="mt.value" :disabled="!mt.selectable">
                            {{ mt.label }}{{ mt.selectable ? '' : ' (em breve)' }}
                        </option>
                    </select>
                    <input
                        v-model.number="createForm.threshold"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="Meta (R$)"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <input
                        v-model.number="createForm.sort_order"
                        type="number"
                        min="0"
                        placeholder="Ordem"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <label class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:text-zinc-200">
                        <input v-model="createForm.is_active" type="checkbox" />
                        Ativa
                    </label>
                    <textarea
                        v-model="createForm.description"
                        rows="2"
                        placeholder="Descrição (modal de celebração)"
                        class="md:col-span-2 lg:col-span-3 rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <input
                        v-model="createForm.reward_name"
                        type="text"
                        placeholder="Nome do prêmio físico"
                        class="rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <textarea
                        v-model="createForm.reward_description"
                        rows="2"
                        placeholder="Descrição do prêmio (visível ao seller)"
                        class="md:col-span-2 rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                    <textarea
                        v-model="createForm.reward_internal_notes"
                        rows="2"
                        placeholder="Notas internas da premiação"
                        class="md:col-span-2 lg:col-span-3 rounded-xl border border-zinc-300 px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    />
                </div>
                <div class="mt-4">
                    <Button type="button" class="inline-flex items-center gap-2" :disabled="creating" @click="createAchievement">
                        <Plus class="h-4 w-4" />
                        {{ creating ? 'Criando...' : 'Criar conquista' }}
                    </Button>
                </div>
            </section>

            <section class="space-y-4">
                <div
                    v-for="item in items"
                    :key="item.id"
                    class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50"
                >
                    <div class="grid gap-4 lg:grid-cols-12">
                        <div class="space-y-3 lg:col-span-2">
                            <p class="text-xs font-medium uppercase text-zinc-500">Badge</p>
                            <img v-if="item.image" :src="item.image" :alt="item.name" class="h-16 w-16 rounded-xl object-cover" />
                            <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-[var(--color-primary)]">
                                <Upload class="h-3.5 w-3.5" />
                                {{ uploadingBadgeId === item.id ? 'Enviando...' : 'Upload badge' }}
                                <input type="file" accept="image/*" class="hidden" @change="(e) => onImageChange(e, item, 'badge')" />
                            </label>
                            <p class="text-xs font-medium uppercase text-zinc-500">Imagem prêmio</p>
                            <img v-if="item.reward_image" :src="item.reward_image" :alt="item.reward_name" class="h-16 w-16 rounded-xl object-cover" />
                            <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-[var(--color-primary)]">
                                <Upload class="h-3.5 w-3.5" />
                                {{ uploadingRewardId === item.id ? 'Enviando...' : 'Upload prêmio' }}
                                <input type="file" accept="image/*" class="hidden" @change="(e) => onImageChange(e, item, 'reward')" />
                            </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:col-span-7">
                            <input v-model="item.name" type="text" placeholder="Nome" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <input v-model="item.slug" type="text" placeholder="Slug" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <select v-model="item.metric_type" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                <option v-for="mt in metric_types" :key="mt.value" :value="mt.value" :disabled="!mt.selectable">
                                    {{ mt.label }}
                                </option>
                            </select>
                            <input v-model.number="item.threshold" type="number" min="0" step="0.01" placeholder="Meta (R$)" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <input v-model.number="item.sort_order" type="number" min="0" placeholder="Ordem" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <label class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:text-zinc-200">
                                <input v-model="item.is_active" type="checkbox" />
                                Ativa
                            </label>
                            <textarea v-model="item.description" rows="2" placeholder="Descrição" class="sm:col-span-2 rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <input v-model="item.reward_name" type="text" placeholder="Nome do prêmio" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <textarea v-model="item.reward_description" rows="2" placeholder="Descrição do prêmio" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <textarea v-model="item.reward_internal_notes" rows="2" placeholder="Notas internas" class="sm:col-span-2 rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        </div>
                        <div class="flex flex-col gap-2 lg:col-span-3 lg:items-end">
                            <Button type="button" size="sm" variant="outline" class="inline-flex w-full items-center justify-center gap-1 lg:w-auto" :disabled="savingId === item.id" @click="saveItem(item)">
                                <Save class="h-3.5 w-3.5" />
                                {{ savingId === item.id ? 'Salvando...' : 'Salvar' }}
                            </Button>
                            <Button type="button" size="sm" variant="outline" class="inline-flex w-full items-center justify-center gap-1 text-red-600 lg:w-auto" :disabled="deletingId === item.id" @click="deleteItem(item)">
                                <Trash2 class="h-3.5 w-3.5" />
                                {{ deletingId === item.id ? 'Removendo...' : 'Remover' }}
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-if="items.length === 0" class="rounded-xl border border-zinc-200 bg-white px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50">
                    Nenhuma conquista cadastrada.
                </p>
            </section>
        </template>

        <!-- Tab: Relatório -->
        <template v-else-if="tab === 'relatorio'">
            <form class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50" @submit.prevent="applyRankFilters">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Busca</label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                            <input
                                v-model="rankFilters.q"
                                type="text"
                                placeholder="Nome ou e-mail"
                                class="w-full rounded-xl border border-zinc-300 py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Métrica</label>
                        <select v-model="rankFilters.metric_type" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option v-for="mt in metric_types" :key="mt.value" :value="mt.value" :disabled="!mt.selectable">
                                {{ mt.label }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Conquista</label>
                        <select v-model="rankFilters.achievement_id" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="">Todas</option>
                            <option v-for="a in achievements" :key="a.id" :value="String(a.id)">{{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Status da conta</label>
                        <select v-model="rankFilters.account_status" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="">Todos</option>
                            <option value="approved">Aprovado</option>
                            <option value="pending">Pendente</option>
                            <option value="suspended">Suspenso</option>
                            <option value="blocked">Bloqueado</option>
                            <option value="rejected">Rejeitado</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Gerente de conta</label>
                        <select v-model="rankFilters.account_manager_id" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="">Todos</option>
                            <option v-for="m in account_managers" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Status premiação</label>
                        <select v-model="rankFilters.reward_status" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="">Todos</option>
                            <option v-for="rs in reward_statuses" :key="rs.value" :value="rs.value">{{ rs.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Desbloqueio de</label>
                        <input v-model="rankFilters.period_from" type="date" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Desbloqueio até</label>
                        <input v-model="rankFilters.period_to" type="date" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-zinc-500">Por página</label>
                        <select v-model="rankFilters.per_page" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4">
                    <Button type="submit">Filtrar</Button>
                </div>
            </form>

            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th class="cursor-pointer px-3 py-3" @click="changeRankSort('position')">#</th>
                                <th class="cursor-pointer px-3 py-3" @click="changeRankSort('name')">Infoprodutor</th>
                                <th class="px-3 py-3">Gerente</th>
                                <th class="cursor-pointer px-3 py-3 text-right" @click="changeRankSort('current_value')">Atual</th>
                                <th class="px-3 py-3 text-right">Meta</th>
                                <th class="cursor-pointer px-3 py-3 text-right" @click="changeRankSort('progress_percent')">%</th>
                                <th class="cursor-pointer px-3 py-3 text-right" @click="changeRankSort('remaining')">Falta</th>
                                <th class="px-3 py-3 min-w-[120px]">Progresso</th>
                                <th class="px-3 py-3">Próxima meta</th>
                                <th class="px-3 py-3">Próximo prêmio</th>
                                <th class="px-3 py-3">Última conquista</th>
                                <th class="px-3 py-3">Conta</th>
                                <th class="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="rankRows.length === 0">
                                <td colspan="13" class="px-4 py-8 text-center text-zinc-500">Nenhum resultado.</td>
                            </tr>
                            <tr v-for="row in rankRows" :key="row.user_id" class="border-b border-zinc-100 align-top dark:border-zinc-800">
                                <td class="px-3 py-3 tabular-nums text-zinc-500">{{ row.position }}</td>
                                <td class="px-3 py-3">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ row.name }}</p>
                                    <p class="text-xs text-zinc-500">{{ row.email }}</p>
                                </td>
                                <td class="px-3 py-3 text-zinc-600 dark:text-zinc-400">{{ row.account_manager?.name || '—' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ formatBRL(row.current_value) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ formatBRL(row.target_value) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ row.progress_percent }}%</td>
                                <td class="px-3 py-3 text-right tabular-nums">{{ row.all_completed ? '—' : formatBRL(row.remaining) }}</td>
                                <td class="px-3 py-3">
                                    <div class="h-2 w-full min-w-[80px] overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div
                                            class="h-full rounded-full bg-[var(--color-primary)]"
                                            :style="{ width: `${Math.min(100, row.progress_percent ?? 0)}%` }"
                                        />
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ row.next_achievement?.name || (row.all_completed ? 'Concluído' : '—') }}</td>
                                <td class="px-3 py-3 text-zinc-700 dark:text-zinc-300">{{ row.next_reward_name || '—' }}</td>
                                <td class="px-3 py-3">
                                    <p class="text-zinc-700 dark:text-zinc-300">{{ row.last_achievement?.name || '—' }}</p>
                                    <p v-if="row.last_achievement?.unlocked_at" class="text-xs text-zinc-500">{{ formatDate(row.last_achievement.unlocked_at) }}</p>
                                </td>
                                <td class="px-3 py-3">{{ accountStatusLabel(row.account_status) }}</td>
                                <td class="px-3 py-3">
                                    <Link
                                        :href="`/plataforma/usuarios/${row.user_id}?tab=achievements`"
                                        class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                    >
                                        Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="rankRangeLabel" class="border-t border-zinc-100 px-4 py-3 text-sm text-zinc-500 dark:border-zinc-700">
                    {{ rankRangeLabel }}
                </div>
            </div>

            <nav v-if="(ranking?.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-2">
                <a
                    v-for="link in ranking.links"
                    :key="link.label + String(link.url)"
                    :href="link.url || undefined"
                    class="rounded-lg px-3 py-2 text-sm"
                    :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                    v-text="htmlToText(link.label)"
                    @click.prevent="link.url && router.visit(link.url, { preserveState: false })"
                />
            </nav>
        </template>

        <!-- Tab: Premiações -->
        <template v-else-if="tab === 'premiacoes'">
            <form class="flex flex-wrap items-end gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50" @submit.prevent="applyRewardsFilter">
                <div class="min-w-[200px]">
                    <label class="mb-1 block text-xs font-medium text-zinc-500">Status</label>
                    <select v-model="rewardsStatusFilter" class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">Todos</option>
                        <option v-for="rs in reward_statuses" :key="rs.value" :value="rs.value">{{ rs.label }}</option>
                    </select>
                </div>
                <Button type="submit">Filtrar</Button>
            </form>

            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                                <th class="px-4 py-3">Infoprodutor</th>
                                <th class="px-4 py-3">Conquista</th>
                                <th class="px-4 py-3">Prêmio</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Desbloqueio</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="rewardRows.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-zinc-500">Nenhuma premiação encontrada.</td>
                            </tr>
                            <tr v-for="row in rewardRows" :key="row.id" class="border-b border-zinc-100 dark:border-zinc-800">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ row.merchant_name }}</p>
                                    <p class="text-xs text-zinc-500">{{ row.merchant_email }}</p>
                                </td>
                                <td class="px-4 py-3">{{ row.achievement_name }}</td>
                                <td class="px-4 py-3">{{ row.reward_name || '—' }}</td>
                                <td class="px-4 py-3">{{ row.reward_status_label }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ formatDate(row.unlocked_at) }}</td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="`/plataforma/usuarios/${row.user_id}?tab=achievements`"
                                        class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                    >
                                        Ver infoprodutor
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
