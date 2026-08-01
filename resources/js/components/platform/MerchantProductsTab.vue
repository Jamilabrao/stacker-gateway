<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import { ExternalLink, Copy, Check, Package } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

const props = defineProps({
    merchantId: { type: Number, required: true },
    products: { type: Object, default: null },
    filters: { type: Object, default: null },
    summary: { type: Object, default: null },
    approvalEnabled: { type: Boolean, default: false },
    typeOptions: { type: Array, default: () => [] },
    productsTotal: { type: Number, default: 0 },
});

const searchQ = ref(props.filters?.products_q ?? '');
const approval = ref(props.filters?.products_approval ?? 'all');
const active = ref(props.filters?.products_active ?? 'all');
const type = ref(props.filters?.products_type ?? '');
const dateFrom = ref(props.filters?.products_date_from ?? '');
const dateTo = ref(props.filters?.products_date_to ?? '');
const perPage = ref(String(props.filters?.products_per_page ?? 25));
const sort = ref(props.filters?.products_sort ?? 'created_at');
const direction = ref(props.filters?.products_direction ?? 'desc');

watch(
    () => props.filters,
    (f) => {
        searchQ.value = f?.products_q ?? '';
        approval.value = f?.products_approval ?? 'all';
        active.value = f?.products_active ?? 'all';
        type.value = f?.products_type ?? '';
        dateFrom.value = f?.products_date_from ?? '';
        dateTo.value = f?.products_date_to ?? '';
        perPage.value = String(f?.products_per_page ?? 25);
        sort.value = f?.products_sort ?? 'created_at';
        direction.value = f?.products_direction ?? 'desc';
    },
    { deep: true }
);

const rows = computed(() => props.products?.data ?? []);
const hasFilters = computed(() => {
    const f = props.filters || {};
    return !!(
        f.products_q ||
        (f.products_approval && f.products_approval !== 'all') ||
        (f.products_active && f.products_active !== 'all') ||
        f.products_type ||
        f.products_date_from ||
        f.products_date_to
    );
});

const rangeLabel = computed(() => {
    const p = props.products;
    if (!p || !p.total) return null;
    const from = p.from ?? 0;
    const to = p.to ?? 0;
    return `Exibindo ${from}–${to} de ${p.total} produtos`;
});

function visitParams(extra = {}) {
    return {
        tab: 'products',
        products_q: searchQ.value?.trim() || undefined,
        products_approval: approval.value !== 'all' ? approval.value : undefined,
        products_active: active.value !== 'all' ? active.value : undefined,
        products_type: type.value || undefined,
        products_date_from: dateFrom.value || undefined,
        products_date_to: dateTo.value || undefined,
        products_per_page: Number(perPage.value) || 25,
        products_sort: sort.value,
        products_direction: direction.value,
        products_page: 1,
        ...extra,
    };
}

function applyFilters() {
    router.get(`/plataforma/usuarios/${props.merchantId}`, visitParams(), {
        preserveState: true,
        replace: true,
    });
}

function changeSort(field) {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        direction.value = field === 'name' ? 'asc' : 'desc';
    }
    applyFilters();
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('pt-BR');
}

function approvalLabel(status) {
    if (status === 'pending') return 'Em análise';
    if (status === 'rejected') return 'Não aprovado';
    return 'Aprovado';
}

function setProductActive(product, isActive) {
    const verb = isActive ? 'ativar' : 'desativar';
    if (!confirm(`Confirma ${verb} o produto "${product.name}"?`)) return;
    router.post(`/plataforma/produtos/${product.id}/ativacao`, { is_active: isActive }, { preserveScroll: true });
}

function approveProduct(product) {
    if (!confirm(`Deseja aprovar este produto?\n\n"${product.name}"`)) return;
    router.post(`/plataforma/produtos/${product.id}/aprovar`, {}, { preserveScroll: true });
}

const rejectModalProduct = ref(null);
const rejectReason = ref('');
const rejectSubmitting = ref(false);

function openRejectModal(product) {
    rejectModalProduct.value = product;
    rejectReason.value = '';
}

function closeRejectModal() {
    rejectModalProduct.value = null;
    rejectReason.value = '';
    rejectSubmitting.value = false;
}

function submitReject() {
    if (!rejectModalProduct.value) return;
    rejectSubmitting.value = true;
    router.post(
        `/plataforma/produtos/${rejectModalProduct.value.id}/rejeitar`,
        { reason: rejectReason.value },
        {
            preserveScroll: true,
            onFinish: () => {
                rejectSubmitting.value = false;
            },
            onSuccess: () => closeRejectModal(),
        }
    );
}

const deliverableModalProduct = ref(null);
const copiedUrl = ref(false);

function openDeliverableModal(product) {
    deliverableModalProduct.value = product;
    copiedUrl.value = false;
}

function closeDeliverableModal() {
    deliverableModalProduct.value = null;
    copiedUrl.value = false;
}

async function copyDeliverableUrl(url) {
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        copiedUrl.value = true;
        setTimeout(() => {
            copiedUrl.value = false;
        }, 2000);
    } catch {
        window.prompt('Copiar URL:', url);
    }
}
</script>

<template>
    <div class="space-y-5">
        <div v-if="summary" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Total</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ summary.total }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Ativos</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">{{ summary.active }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Inativos</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ summary.inactive }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Em análise</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-amber-700 dark:text-amber-300">{{ summary.pending }}</p>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <p class="text-xs uppercase text-zinc-500">Não aprovados</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-red-700 dark:text-red-300">{{ summary.rejected }}</p>
            </div>
        </div>

        <form class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800" @submit.prevent="applyFilters">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="Nome ou ID"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <select v-model="approval" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                    <option value="all">Aprovação: todos</option>
                    <option value="pending">Em análise</option>
                    <option value="approved">Aprovado</option>
                    <option value="rejected">Não aprovado</option>
                </select>
                <select v-model="active" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                    <option value="all">Ativação: todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </select>
                <select v-model="type" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                    <option value="">Tipo: todos</option>
                    <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <input v-model="dateFrom" type="date" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                <input v-model="dateTo" type="date" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                <select v-model="perPage" class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">
                    <option value="25">25 / página</option>
                    <option value="50">50 / página</option>
                    <option value="100">100 / página</option>
                </select>
                <Button type="submit">Filtrar</Button>
            </div>
        </form>

        <p v-if="rangeLabel" class="text-sm text-zinc-600 dark:text-zinc-400">{{ rangeLabel }}</p>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="overflow-x-auto">
                <table class="min-w-[1100px] w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-900/50">
                        <tr>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('name')">Produto</button>
                            </th>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">Tipo</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('price')">Preço</button>
                            </th>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('approval_status')">Aprovação</button>
                            </th>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">Publicação</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('sales_count')">Vendas</button>
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('sales_total')">Total</button>
                            </th>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('created_at')">Criado</button>
                            </th>
                            <th class="px-3 py-2 text-xs font-semibold uppercase text-zinc-500">
                                <button type="button" class="hover:underline" @click="changeSort('updated_at')">Atualizado</button>
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-zinc-500">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-if="!rows.length">
                            <td colspan="10" class="px-4 py-10 text-center text-zinc-500">
                                <Package class="mx-auto mb-2 h-8 w-8 opacity-40" />
                                {{
                                    hasFilters || productsTotal > 0
                                        ? 'Nenhum produto encontrado com os filtros aplicados.'
                                        : 'Este infoprodutor ainda não criou produtos.'
                                }}
                            </td>
                        </tr>
                        <tr v-for="p in rows" :key="p.id" class="hover:bg-zinc-50/80 dark:hover:bg-zinc-900/40">
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="p.image_url"
                                        :src="p.image_url"
                                        alt=""
                                        class="h-10 w-10 rounded-lg object-cover"
                                    />
                                    <div
                                        v-else
                                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-zinc-100 text-xs dark:bg-zinc-700"
                                    >
                                        —
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ p.name }}</div>
                                        <div class="text-xs text-zinc-500">ID {{ p.id }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3">{{ p.type_label }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatBRL(p.price) }}</td>
                            <td class="px-3 py-3">
                                <span
                                    v-if="approvalEnabled"
                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="{
                                        'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200':
                                            p.approval_status === 'pending',
                                        'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200':
                                            p.approval_status === 'rejected',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200':
                                            p.approval_status === 'approved' || !p.approval_status,
                                    }"
                                >
                                    {{ approvalLabel(p.approval_status) }}
                                </span>
                                <div v-if="p.reviewed_by_name || p.reviewed_at" class="mt-1 text-[11px] text-zinc-500">
                                    <span v-if="p.reviewed_by_name">{{ p.reviewed_by_name }}</span>
                                    <span v-if="p.reviewed_at"> · {{ formatDate(p.reviewed_at) }}</span>
                                </div>
                                <div v-if="p.approval_reason" class="mt-0.5 max-w-[180px] truncate text-[11px] text-amber-700" :title="p.approval_reason">
                                    {{ p.approval_reason }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-xs">{{ p.publication_label }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ p.sales_count }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatBRL(p.sales_total) }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-xs text-zinc-500">{{ formatDate(p.created_at) }}</td>
                            <td class="px-3 py-3 whitespace-nowrap text-xs text-zinc-500">{{ formatDate(p.updated_at) }}</td>
                            <td class="px-3 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <Button type="button" size="sm" variant="secondary" @click="openDeliverableModal(p)">
                                        Visualizar
                                    </Button>
                                    <Button v-if="p.can_approve" type="button" size="sm" @click="approveProduct(p)">Aprovar</Button>
                                    <Button
                                        v-if="p.can_reject"
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        class="!text-amber-800"
                                        @click="openRejectModal(p)"
                                    >
                                        Não aprovar
                                    </Button>
                                    <Button
                                        v-if="p.can_activate"
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        @click="setProductActive(p, true)"
                                    >
                                        Ativar
                                    </Button>
                                    <Button
                                        v-if="p.can_deactivate"
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        @click="setProductActive(p, false)"
                                    >
                                        Desativar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav v-if="(products?.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-2">
            <a
                v-for="link in products.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                class="rounded-lg px-3 py-2 text-sm"
                :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>

        <div
            v-if="deliverableModalProduct"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="closeDeliverableModal"
        >
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-5 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">{{ deliverableModalProduct.name }}</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ deliverableModalProduct.deliverable_preview?.title }}</p>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ deliverableModalProduct.deliverable_preview?.description }}
                </p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a
                        v-if="deliverableModalProduct.deliverable_preview?.primary_url"
                        :href="deliverableModalProduct.deliverable_preview.primary_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white"
                    >
                        <ExternalLink class="h-4 w-4" />
                        {{ deliverableModalProduct.deliverable_preview.open_label || 'Abrir' }}
                    </a>
                    <a
                        v-if="deliverableModalProduct.deliverable_preview?.checkout_url"
                        :href="deliverableModalProduct.deliverable_preview.checkout_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600"
                    >
                        Ver checkout
                    </a>
                    <button
                        v-if="deliverableModalProduct.deliverable_preview?.primary_url"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600"
                        @click="copyDeliverableUrl(deliverableModalProduct.deliverable_preview.primary_url)"
                    >
                        <Check v-if="copiedUrl" class="h-4 w-4" />
                        <Copy v-else class="h-4 w-4" />
                        {{ copiedUrl ? 'Copiado' : 'Copiar URL' }}
                    </button>
                    <Button type="button" variant="secondary" @click="closeDeliverableModal">Fechar</Button>
                </div>
            </div>
        </div>

        <div
            v-if="rejectModalProduct"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            @click.self="closeRejectModal"
        >
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">Não aprovar produto</h3>
                <p class="mt-1 text-sm text-zinc-500">{{ rejectModalProduct.name }}</p>
                <textarea
                    v-model="rejectReason"
                    rows="3"
                    class="mt-3 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                    placeholder="Motivo (opcional)"
                />
                <div class="mt-4 flex justify-end gap-2">
                    <Button type="button" variant="secondary" @click="closeRejectModal">Cancelar</Button>
                    <Button type="button" :disabled="rejectSubmitting" @click="submitReject">
                        {{ rejectSubmitting ? 'Enviando…' : 'Confirmar' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
