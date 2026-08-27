<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { BadgeCheck, ChevronDown, ChevronUp, Eye, MessageSquare, Pencil, Search, Shield, Trash2, UserPlus } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    users: { type: [Object, Array], default: () => ({ data: [], links: [], total: 0 }) },
    q: { type: String, default: null },
    status: { type: String, default: null },
    sort_by: { type: String, default: null },
    sort_direction: { type: String, default: null },
    per_page: { type: Number, default: 25 },
    status_options: { type: Array, default: () => [] },
});

const page = usePage();
const searchQ = ref(props.q ?? '');
const statusFilter = ref(props.status ?? '');
const perPage = ref(Number(props.per_page) || 25);
const deletingId = ref(null);
const selectedIds = ref([]);
const bulkDeleteOpen = ref(false);
const bulkDeleteLoading = ref(false);
const bulkStepUpOpen = ref(false);
const bulkDeleteForce = ref(false);

watch(() => props.q, (value) => { searchQ.value = value ?? ''; });
watch(() => props.status, (value) => { statusFilter.value = value ?? ''; });
watch(() => props.per_page, (value) => { perPage.value = Number(value) || 25; });

const usersList = computed(() => Array.isArray(props.users?.data) ? props.users.data : (Array.isArray(props.users) ? props.users : []));
const paginationLinks = computed(() => Array.isArray(props.users?.links) ? props.users.links : []);
const usersMeta = computed(() => {
    if (props.users && typeof props.users === 'object' && !Array.isArray(props.users)) return props.users;
    return { total: usersList.value.length, from: usersList.value.length ? 1 : null, to: usersList.value.length };
});
const selectedCount = computed(() => selectedIds.value.length);
const allVisibleSelected = computed(() => usersList.value.length > 0 && usersList.value.every((user) => selectedIds.value.includes(user.id)));
const bulkDeleteTargets = computed(() => usersList.value.filter((user) => selectedIds.value.includes(user.id)));
const bulkDeleteResult = computed(() => page.props.flash?.bulk_delete_result ?? null);
const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));

function listingQuery(overrides = {}) {
    const sortBy = overrides.sort_by !== undefined ? overrides.sort_by : props.sort_by || undefined;
    const query = {
        q: overrides.q !== undefined ? overrides.q : searchQ.value.trim() || undefined,
        status: overrides.status !== undefined ? overrides.status : statusFilter.value || undefined,
        sort_by: sortBy,
        sort_direction: sortBy ? (overrides.sort_direction ?? props.sort_direction ?? 'asc') : undefined,
        per_page: Number(overrides.per_page ?? perPage.value) || 25,
    };
    const currentPage = Object.prototype.hasOwnProperty.call(overrides, 'page') ? overrides.page : props.users?.current_page;
    if (Number(currentPage) > 1) query.page = Number(currentPage);
    return query;
}

function applySearch() {
    router.get('/plataforma/usuarios', listingQuery({ page: 1 }), { preserveState: true, replace: true });
}
function clearFilters() {
    searchQ.value = '';
    statusFilter.value = '';
    router.get('/plataforma/usuarios', listingQuery({ q: undefined, status: undefined, page: 1 }), { preserveState: true, replace: true });
}
function changePerPage() {
    router.get('/plataforma/usuarios', listingQuery({ per_page: perPage.value, page: 1 }), { preserveState: true, replace: true });
}
function toggleSort(column) {
    const direction = props.sort_by === column && props.sort_direction === 'asc' ? 'desc' : 'asc';
    router.get('/plataforma/usuarios', listingQuery({ sort_by: column, sort_direction: direction, page: 1 }), { preserveState: true, replace: true });
}
function sortIndicator(column) {
    return props.sort_by === column ? (props.sort_direction === 'desc' ? 'desc' : 'asc') : null;
}
function toggleUserSelection(id) {
    selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter((value) => value !== id) : [...selectedIds.value, id];
}
function toggleSelectAllVisible() {
    const visible = new Set(usersList.value.map((user) => user.id));
    selectedIds.value = allVisibleSelected.value
        ? selectedIds.value.filter((id) => !visible.has(id))
        : [...new Set([...selectedIds.value, ...visible])];
}
function selectPendingWithoutSales() {
    const ids = usersList.value.filter((user) => (user.account_status || 'approved') === 'pending' && Number(user.vendas_totais || 0) === 0).map((user) => user.id);
    selectedIds.value = [...new Set([...selectedIds.value, ...ids])];
}
function destroyUser(id) {
    if (!confirm('Excluir este infoprodutor? Esta ação não pode ser desfeita.')) return;
    deletingId.value = id;
    router.delete(`/plataforma/usuarios/${id}`, { preserveScroll: true, onFinish: () => { deletingId.value = null; } });
}
function openBulkDeleteModal(force = false) {
    if (!selectedIds.value.length) return;
    bulkDeleteForce.value = force;
    bulkDeleteOpen.value = true;
}
function closeBulkDeleteModal() {
    bulkDeleteOpen.value = false;
    bulkDeleteLoading.value = false;
}
function requestBulkDelete() {
    if (platformTotpEnabled.value) {
        bulkDeleteOpen.value = false;
        bulkStepUpOpen.value = true;
        return;
    }
    submitBulkDelete();
}
function submitBulkDelete(totpCode = '') {
    bulkDeleteLoading.value = true;
    router.post('/plataforma/usuarios/excluir-em-massa', {
        ids: selectedIds.value, confirm: true, force: bulkDeleteForce.value, totp_code: totpCode || undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; closeBulkDeleteModal(); },
        onFinish: () => { bulkDeleteLoading.value = false; bulkStepUpOpen.value = false; },
    });
}
function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}
function formatCreatedAt(value) {
    const date = value ? new Date(value) : null;
    return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString('pt-BR') : '—';
}
function statusLabel(status) {
    return { approved: 'Aprovado', pending: 'Pendente', rejected: 'Rejeitado', suspended: 'Suspenso', blocked: 'Bloqueado' }[status] || status || '—';
}
function hasCustomFees(user) {
    return user?.merchant_fees && Object.keys(user.merchant_fees).length > 0;
}
function hasCustomSettlement(user) {
    return user?.merchant_settlement_overrides && Object.keys(user.merchant_settlement_overrides).length > 0;
}
function documentTypeLabel(user) {
    if (user?.document_type === 'CPF' || user?.document_type === 'CNPJ') return user.document_type;
    if (user?.person_type === 'pj') return 'CNPJ';
    if (user?.person_type === 'pf') return 'CPF';
    const digits = String(user?.document || '').replace(/\D/g, '');
    if (digits.length === 14) return 'CNPJ';
    if (digits.length === 11) return 'CPF';
    return '—';
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Infoprodutores</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Saldo, documento e status da conta</p>
            </div>
            <Link href="/plataforma/usuarios/create" class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-zinc-900 px-4 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900">
                <UserPlus class="h-4 w-4" /> Novo infoprodutor
            </Link>
        </div>

        <form class="flex flex-wrap items-center gap-2" @submit.prevent="applySearch">
            <div class="relative min-w-[200px] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                <input v-model="searchQ" type="search" placeholder="Nome, e-mail, CPF/CNPJ ou ID" class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
            </div>
            <select v-model="statusFilter" class="min-w-[11rem] rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="applySearch">
                <option value="">Todos os status</option>
                <option v-for="option in status_options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <button type="submit" class="rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white">Pesquisar</button>
            <button v-if="searchQ || statusFilter" type="button" class="rounded-xl border border-zinc-300 px-4 py-2 text-sm" @click="clearFilters">Limpar</button>
            <label class="ml-auto flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <span>Por página</span>
                <select v-model="perPage" class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="changePerPage">
                    <option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                </select>
            </label>
        </form>

        <p v-if="page.props.flash?.success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ page.props.flash.success }}</p>
        <p v-if="page.props.flash?.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ page.props.flash.error }}</p>
        <div v-if="bulkDeleteResult" class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900/60">
            <p class="font-medium">Resultado da exclusão em massa</p>
            <p v-if="bulkDeleteResult.deleted?.length" class="mt-2 text-xs">Excluídos: {{ bulkDeleteResult.deleted.join(', ') }}</p>
            <ul v-if="bulkDeleteResult.skipped?.length" class="mt-2 list-inside list-disc text-xs">
                <li v-for="row in bulkDeleteResult.skipped" :key="`${row.id}-${row.reason}`">#{{ row.id }} — {{ row.reason }}</li>
            </ul>
        </div>
        <div v-if="selectedCount" class="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/60">
            <span class="text-sm font-medium">{{ selectedCount }} selecionado(s)</span>
            <button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white" @click="openBulkDeleteModal()">Excluir selecionados</button>
            <button type="button" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm" @click="selectPendingWithoutSales">Selecionar pendentes sem vendas</button>
            <button type="button" class="rounded-lg px-3 py-1.5 text-sm" @click="selectedIds = []">Limpar seleção</button>
        </div>

        <div v-if="usersList.length" class="space-y-3 md:hidden">
            <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900/60">
                <label class="inline-flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" class="rounded border-zinc-300" :checked="allVisibleSelected" :disabled="!usersList.length" @change="toggleSelectAllVisible" />
                    Selecionar todos
                </label>
                <span class="text-xs text-zinc-500">{{ usersList.length }} nesta página</span>
            </div>
            <article
                v-for="user in usersList"
                :key="`mobile-${user.id}`"
                class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60"
            >
                <div class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        class="mt-1 rounded border-zinc-300"
                        :checked="selectedIds.includes(user.id)"
                        @change="toggleUserSelection(user.id)"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-semibold text-zinc-900 dark:text-white">{{ user.name }}</p>
                        <p class="mt-0.5 break-all text-xs text-zinc-500 dark:text-zinc-400">{{ user.trade_name || user.email }}</p>
                        <p v-if="user.trade_name" class="mt-0.5 break-all text-[11px] text-zinc-400">{{ user.email }}</p>
                        <p class="mt-1 text-xs text-zinc-400">ID {{ user.id }}</p>
                        <div class="mt-2 flex flex-wrap gap-1">
                            <span
                                v-if="user.totp_enabled"
                                class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-950 dark:bg-emerald-700 dark:text-white"
                                title="Autenticação em dois fatores ativa"
                            >
                                <Shield class="h-3 w-3" />2FA
                            </span>
                            <span
                                v-if="user.admin_notes_count"
                                class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-950 dark:bg-amber-600 dark:text-white"
                                title="Observações internas"
                            >
                                <MessageSquare class="h-3 w-3" />{{ user.admin_notes_count }}
                            </span>
                            <span
                                v-if="hasCustomFees(user)"
                                class="rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-950 dark:bg-violet-700 dark:text-white"
                                title="Taxas personalizadas"
                            >
                                Taxas custom
                            </span>
                            <span
                                v-if="hasCustomSettlement(user)"
                                class="rounded-md bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-950 dark:bg-sky-700 dark:text-white"
                                title="Liquidação personalizada"
                            >
                                Liquidação custom
                            </span>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <dt class="text-zinc-500">Tipo</dt>
                                <dd class="text-zinc-800 dark:text-zinc-200">{{ documentTypeLabel(user) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Documento</dt>
                                <dd class="break-all text-zinc-800 dark:text-zinc-200">{{ user.document || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Status</dt>
                                <dd>
                                    <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                        {{ statusLabel(user.account_status) }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Cadastro</dt>
                                <dd class="text-zinc-800 dark:text-zinc-200">{{ formatCreatedAt(user.created_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">Vendas</dt>
                                <dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(user.vendas_totais) }}</dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-zinc-500">Saldo disponível</dt>
                                <dd class="font-semibold tabular-nums text-zinc-900 dark:text-white" :title="`Pendente: ${formatBRL(user.saldo_pix)}`">
                                    {{ formatBRL(user.saldo_disponivel) }}
                                </dd>
                                <p class="mt-0.5 text-[11px] text-zinc-400">Pendente: {{ formatBRL(user.saldo_pix) }}</p>
                            </div>
                        </dl>
                    </div>
                </div>
                <div class="mt-4 flex flex-col gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                    <Link :href="`/plataforma/usuarios/${user.id}`" class="block">
                        <Button type="button" size="sm" class="w-full justify-center">
                            <Eye class="h-4 w-4" /> Visualizar
                        </Button>
                    </Link>
                    <Link :href="`/plataforma/verificacoes-kyc/usuario/${user.id}`" class="block">
                        <Button type="button" size="sm" variant="secondary" class="w-full justify-center">
                            <BadgeCheck class="h-4 w-4" /> KYC
                        </Button>
                    </Link>
                    <Link :href="`/plataforma/usuarios/${user.id}/edit`" class="block">
                        <Button type="button" size="sm" variant="secondary" class="w-full justify-center">
                            <Pencil class="h-4 w-4" /> Editar
                        </Button>
                    </Link>
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        class="w-full justify-center !text-red-700 dark:!text-red-300"
                        :disabled="deletingId === user.id"
                        @click="destroyUser(user.id)"
                    >
                        <Trash2 class="h-4 w-4" /> Excluir
                    </Button>
                </div>
            </article>
        </div>

        <div
            v-if="!usersList.length"
            class="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-500 md:hidden dark:border-zinc-700"
        >
            {{ q ? 'Nenhum infoprodutor encontrado.' : 'Nenhum infoprodutor cadastrado.' }}
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/60 md:block">
            <div class="overflow-x-auto">
                <table class="w-full table-fixed text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/80">
                        <tr>
                            <th class="w-10 px-3 py-2.5"><input type="checkbox" :checked="allVisibleSelected" :disabled="!usersList.length" @change="toggleSelectAllVisible" /></th>
                            <th class="w-[24%] px-3 py-2.5">Infoprodutor</th>
                            <th class="w-[7%] px-3 py-2.5">Tipo</th>
                            <th class="w-[13%] px-3 py-2.5">Documento</th>
                            <th class="w-[10%] px-3 py-2.5">Status</th>
                            <th class="w-[12%] px-3 py-2.5"><button class="inline-flex items-center gap-1 uppercase" @click="toggleSort('created_at')">Cadastro <ChevronUp v-if="sortIndicator('created_at') === 'asc'" class="h-3 w-3" /><ChevronDown v-else-if="sortIndicator('created_at') === 'desc'" class="h-3 w-3" /></button></th>
                            <th class="w-[12%] px-3 py-2.5 text-right"><button class="inline-flex items-center gap-1 uppercase" @click="toggleSort('total_sales')">Vendas <ChevronUp v-if="sortIndicator('total_sales') === 'asc'" class="h-3 w-3" /><ChevronDown v-else-if="sortIndicator('total_sales') === 'desc'" class="h-3 w-3" /></button></th>
                            <th class="w-[12%] px-3 py-2.5 text-right"><button class="inline-flex items-center gap-1 uppercase" @click="toggleSort('balance')">Saldo <ChevronUp v-if="sortIndicator('balance') === 'asc'" class="h-3 w-3" /><ChevronDown v-else-if="sortIndicator('balance') === 'desc'" class="h-3 w-3" /></button></th>
                            <th class="w-[13%] px-3 py-2.5 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in usersList" :key="user.id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="px-3 py-2.5"><input type="checkbox" :checked="selectedIds.includes(user.id)" @change="toggleUserSelection(user.id)" /></td>
                            <td class="min-w-0 px-3 py-2.5">
                                <div class="truncate font-medium text-zinc-900 dark:text-white">{{ user.name }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ user.trade_name || user.email }}</div>
                                <div v-if="user.trade_name" class="truncate text-[11px] text-zinc-400">{{ user.email }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span
                                        v-if="user.totp_enabled"
                                        class="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-950 dark:bg-emerald-700 dark:text-white"
                                        title="Autenticação em dois fatores ativa"
                                    >
                                        <Shield class="h-3 w-3" />2FA
                                    </span>
                                    <span
                                        v-if="user.admin_notes_count"
                                        class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-950 dark:bg-amber-600 dark:text-white"
                                        title="Observações internas"
                                    >
                                        <MessageSquare class="h-3 w-3" />{{ user.admin_notes_count }}
                                    </span>
                                    <span
                                        v-if="hasCustomFees(user)"
                                        class="rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold text-violet-950 dark:bg-violet-700 dark:text-white"
                                        title="Taxas personalizadas"
                                    >
                                        Taxas custom
                                    </span>
                                    <span
                                        v-if="hasCustomSettlement(user)"
                                        class="rounded-md bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-950 dark:bg-sky-700 dark:text-white"
                                        title="Liquidação personalizada"
                                    >
                                        Liquidação custom
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ documentTypeLabel(user) }}</span>
                            </td>
                            <td class="truncate px-3 py-2.5 text-zinc-600">{{ user.document || '—' }}</td>
                            <td class="px-3 py-2.5"><span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ statusLabel(user.account_status) }}</span></td>
                            <td class="truncate px-3 py-2.5 text-zinc-600">{{ formatCreatedAt(user.created_at) }}</td>
                            <td class="truncate px-3 py-2.5 text-right tabular-nums font-medium">{{ formatBRL(user.vendas_totais) }}</td>
                            <td class="truncate px-3 py-2.5 text-right tabular-nums" :title="`Pendente: ${formatBRL(user.saldo_pix)}`">{{ formatBRL(user.saldo_disponivel) }}</td>
                            <td class="px-3 py-2.5"><div class="flex flex-wrap justify-end gap-1">
                                <Link :href="`/plataforma/usuarios/${user.id}`" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100" title="Ver"><Eye class="h-4 w-4" /></Link>
                                <Link :href="`/plataforma/verificacoes-kyc/usuario/${user.id}`" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100" title="KYC"><BadgeCheck class="h-4 w-4" /></Link>
                                <Link :href="`/plataforma/usuarios/${user.id}/edit`" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100" title="Editar"><Pencil class="h-4 w-4" /></Link>
                                <button type="button" class="rounded-lg p-1.5 text-red-500 hover:bg-red-50" :disabled="deletingId === user.id" @click="destroyUser(user.id)"><Trash2 class="h-4 w-4" /></button>
                            </div></td>
                        </tr>
                        <tr v-if="!usersList.length"><td colspan="9" class="px-3 py-10 text-center text-zinc-500">{{ q ? 'Nenhum infoprodutor encontrado.' : 'Nenhum infoprodutor cadastrado.' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="usersMeta.total > 0" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-zinc-500">Exibindo {{ usersMeta.from ?? 0 }}–{{ usersMeta.to ?? 0 }} de {{ usersMeta.total }} infoprodutores</p>
            <div v-if="paginationLinks.length > 3" class="flex flex-wrap gap-1">
                <Link v-for="(link, index) in paginationLinks" :key="index" :href="link.url || '#'" class="rounded-lg px-3 py-1.5 text-sm" :class="link.active ? 'bg-[var(--color-primary)] text-white' : link.url ? 'text-zinc-600 hover:bg-zinc-100' : 'pointer-events-none text-zinc-300'" v-text="htmlToText(link.label)" />
            </div>
        </div>

        <div v-if="bulkDeleteOpen" class="fixed inset-0 z-[200001] flex items-center justify-center bg-black/50 p-4" @click.self="closeBulkDeleteModal">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">Excluir contas selecionadas</h3>
                <p class="mt-2 text-sm text-zinc-600">Esta ação não pode ser desfeita. Contas com saldo ou pedidos pagos/em disputa serão ignoradas.</p>
                <ul class="mt-4 max-h-48 space-y-1 overflow-y-auto text-sm"><li v-for="user in bulkDeleteTargets" :key="user.id">#{{ user.id }} — {{ user.name }} ({{ user.email }})</li></ul>
                <div class="mt-6 flex justify-end gap-2"><Button type="button" variant="secondary" :disabled="bulkDeleteLoading" @click="closeBulkDeleteModal">Cancelar</Button><Button type="button" :disabled="bulkDeleteLoading" @click="requestBulkDelete">Excluir {{ selectedCount }} conta(s)</Button></div>
            </div>
        </div>
        <PlatformStepUpModal :open="bulkStepUpOpen" title="Confirmar exclusão em massa" description="Informe o código 2FA para excluir as contas selecionadas." confirm-label="Excluir contas" :loading="bulkDeleteLoading" @close="bulkStepUpOpen = false" @confirm="(payload) => submitBulkDelete(payload.totp_code)" />
    </div>
</template>
