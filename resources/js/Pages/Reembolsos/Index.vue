<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import Button from '@/components/ui/Button.vue';
import { X } from 'lucide-vue-next';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    requests: { type: Object, required: true },
    filter_status: { type: String, default: 'pending' },
    order_ids_with_open_med: { type: Array, default: () => [] },
});

const openMedSet = computed(() => new Set(props.order_ids_with_open_med ?? []));

function hasOpenMed(rr) {
    return openMedSet.value.has(rr.order_id);
}

const page = usePage();
const rows = computed(() => props.requests?.data ?? []);
const detailOpen = ref(false);
const detailRequest = ref(null);
const rejectOpen = ref(false);
const rejectId = ref(null);

const rejectForm = useForm({
    reason: '',
});

const statusTabs = [
    { value: 'pending', label: 'Pendentes' },
    { value: 'approved', label: 'Aprovados' },
    { value: 'rejected', label: 'Recusados' },
    { value: 'all', label: 'Todos' },
];

watch(
    () => props.filter_status,
    () => {
        closeDetail();
        closeReject();
    }
);

function formatBRL(n) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(n) || 0);
}

function formatDate(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function orderRef(rr) {
    return rr.order?.public_reference || rr.order_id;
}

function purchaseDate(rr) {
    return rr.order?.created_at ?? null;
}

function requestDate(rr) {
    return rr.created_at ?? null;
}

function statusLabel(s) {
    const map = { pending: 'Pendente', approved: 'Aprovado', rejected: 'Recusado' };
    return map[s] ?? s ?? '—';
}

function statusClass(s) {
    if (s === 'pending') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100';
    if (s === 'approved') return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100';
    if (s === 'rejected') return 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100';
    return 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
}

function setFilter(status) {
    router.get(
        '/vendas/reembolsos',
        { status },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function openDetail(rr) {
    detailRequest.value = rr;
    detailOpen.value = true;
}

function closeDetail() {
    detailOpen.value = false;
    detailRequest.value = null;
}

function approve(rr) {
    if (hasOpenMed(rr)) {
        alert('Reembolso bloqueado: existe disputa MED aberta neste pedido. Resolva em Disputas MED.');
        return;
    }
    if (!confirm(`Aprovar reembolso do pedido #${orderRef(rr)}?`)) return;
    closeDetail();
    router.post(`/vendas/reembolsos/${rr.id}/aprovar`, {}, { preserveScroll: true });
}

function openReject(rr) {
    rejectId.value = rr.id;
    rejectForm.reason = '';
    rejectForm.clearErrors();
    closeDetail();
    rejectOpen.value = true;
}

function closeReject() {
    rejectOpen.value = false;
    rejectId.value = null;
}

function submitReject() {
    if (!rejectId.value) return;
    rejectForm.post(`/vendas/reembolsos/${rejectId.value}/recusar`, {
        preserveScroll: true,
        onSuccess: () => closeReject(),
    });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Vendas</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Solicitações de compradores e reembolsos feitos por você na venda.
            </p>
        </div>

        <VendasTabs />

        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Pendentes = pedidos do cliente. Aprovados = reembolsos concluídos (incluindo os iniciados em Vendas).
        </p>

        <div
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100"
        >
            {{ page.props.flash.error }}
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in statusTabs"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                :class="
                    filter_status === tab.value
                        ? 'bg-[var(--color-primary)] text-white'
                        : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'
                "
                @click="setFilter(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div
            v-if="!rows.length"
            class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/80 p-10 text-center dark:border-zinc-700 dark:bg-zinc-900/40"
        >
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Nenhuma solicitação neste filtro.</p>
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Pedido</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Cliente</th>
                        <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 md:table-cell dark:text-zinc-400">
                            Datas
                        </th>
                        <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 lg:table-cell dark:text-zinc-400">
                            Produto
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Estado</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="rr in rows" :key="rr.id" class="align-top hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40">
                        <td class="px-4 py-3 text-sm">
                            <span class="font-medium text-zinc-900 dark:text-white">#{{ orderRef(rr) }}</span>
                            <p v-if="rr.order?.amount != null" class="text-xs text-zinc-500">
                                {{ formatBRL(rr.order.amount) }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-800 dark:text-zinc-200">
                            {{ rr.user?.name ?? '—' }}
                            <p class="text-xs text-zinc-500">{{ rr.user?.email }}</p>
                        </td>
                        <td class="hidden px-4 py-3 text-sm text-zinc-600 md:table-cell dark:text-zinc-400">
                            <p>
                                <span class="text-xs text-zinc-500">Compra:</span>
                                {{ formatDate(purchaseDate(rr)) }}
                            </p>
                            <p class="mt-0.5">
                                <span class="text-xs text-zinc-500">Pedido:</span>
                                {{ formatDate(requestDate(rr)) }}
                            </p>
                        </td>
                        <td class="hidden px-4 py-3 text-sm text-zinc-700 lg:table-cell dark:text-zinc-300">
                            {{ rr.order?.product?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(rr.status)">
                                {{ statusLabel(rr.status) }}
                            </span>
                            <button
                                v-if="rr.customer_reason"
                                type="button"
                                class="mt-1 block max-w-xs truncate text-left text-xs text-[var(--color-primary)] underline-offset-2 hover:underline"
                                @click="openDetail(rr)"
                            >
                                {{ rr.customer_reason }}
                            </button>
                            <button
                                v-else
                                type="button"
                                class="mt-1 block text-left text-xs text-zinc-500 underline-offset-2 hover:underline dark:text-zinc-400"
                                @click="openDetail(rr)"
                            >
                                Ver detalhes
                            </button>
                            <p
                                v-if="rr.status === 'rejected' && rr.seller_rejection_reason"
                                class="mt-1 max-w-xs truncate text-xs text-red-600 dark:text-red-400"
                                :title="rr.seller_rejection_reason"
                            >
                                Motivo: {{ rr.seller_rejection_reason }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div v-if="rr.status === 'pending'" class="flex flex-wrap justify-end gap-2">
                                <p v-if="hasOpenMed(rr)" class="text-xs text-orange-600 dark:text-orange-400">MED aberta</p>
                                <Button type="button" class="text-xs" :disabled="hasOpenMed(rr)" @click="approve(rr)">Aprovar</Button>
                                <Button type="button" variant="secondary" class="text-xs" @click="openReject(rr)">Recusar</Button>
                            </div>
                            <Button
                                v-else
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-xs"
                                @click="openDetail(rr)"
                            >
                                Detalhes
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="(requests?.links?.length ?? 0) > 3"
            class="flex flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in requests.links"
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
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true, preserveScroll: true })"
            />
        </nav>

        <Teleport to="body">
            <div
                v-if="detailOpen && detailRequest"
                class="fixed inset-0 z-[200000] flex items-end justify-center bg-black/50 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                aria-labelledby="refund-detail-title"
                @click.self="closeDetail"
            >
                <div
                    class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="refund-detail-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                                Solicitação #{{ orderRef(detailRequest) }}
                            </h2>
                            <span
                                class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusClass(detailRequest.status)"
                            >
                                {{ statusLabel(detailRequest.status) }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            @click="closeDetail"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Cliente</dt>
                            <dd class="sm:col-span-2 text-zinc-900 dark:text-white">
                                {{ detailRequest.user?.name ?? '—' }}
                                <span v-if="detailRequest.user?.email" class="block text-xs text-zinc-500">
                                    {{ detailRequest.user.email }}
                                </span>
                            </dd>
                        </div>
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Produto</dt>
                            <dd class="sm:col-span-2 text-zinc-900 dark:text-white">
                                {{ detailRequest.order?.product?.name ?? '—' }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Valor</dt>
                            <dd class="sm:col-span-2 text-zinc-900 dark:text-white">
                                {{ detailRequest.order?.amount != null ? formatBRL(detailRequest.order.amount) : '—' }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Data da compra</dt>
                            <dd class="sm:col-span-2 text-zinc-900 dark:text-white">
                                {{ formatDate(purchaseDate(detailRequest)) }}
                            </dd>
                        </div>
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">Pedido de reembolso</dt>
                            <dd class="sm:col-span-2 text-zinc-900 dark:text-white">
                                {{ formatDate(requestDate(detailRequest)) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-5">
                        <h3 class="text-sm font-medium text-zinc-900 dark:text-white">Mensagem do comprador</h3>
                        <p
                            class="mt-2 whitespace-pre-wrap rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-3 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-200"
                        >
                            {{ detailRequest.customer_reason || 'Nenhuma mensagem informada.' }}
                        </p>
                    </div>

                    <div
                        v-if="detailRequest.status === 'rejected'"
                        class="mt-4"
                    >
                        <h3 class="text-sm font-medium text-zinc-900 dark:text-white">Motivo da recusa</h3>
                        <p
                            class="mt-2 whitespace-pre-wrap rounded-xl border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-100"
                        >
                            {{ detailRequest.seller_rejection_reason || 'Nenhum motivo informado.' }}
                        </p>
                    </div>

                    <div
                        v-if="detailRequest.status === 'pending'"
                        class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
                    >
                        <p
                            v-if="hasOpenMed(detailRequest)"
                            class="mb-1 w-full text-xs text-orange-600 sm:mb-0 sm:mr-auto sm:w-auto dark:text-orange-400"
                        >
                            MED aberta — resolva em Disputas MED antes de aprovar.
                        </p>
                        <Button type="button" variant="secondary" @click="openReject(detailRequest)">Recusar</Button>
                        <Button
                            type="button"
                            :disabled="hasOpenMed(detailRequest)"
                            @click="approve(detailRequest)"
                        >
                            Aprovar
                        </Button>
                    </div>
                    <div v-else class="mt-6 flex justify-end">
                        <Button type="button" variant="secondary" @click="closeDetail">Fechar</Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="rejectOpen"
                class="fixed inset-0 z-[200000] flex items-end justify-center bg-black/50 p-4 sm:items-center"
                role="dialog"
                aria-modal="true"
                @click.self="closeReject"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                    @click.stop
                >
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Recusar reembolso</h2>
                        <button type="button" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="closeReject">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <textarea
                        v-model="rejectForm.reason"
                        rows="4"
                        class="mt-4 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                        placeholder="Motivo (opcional, visível ao cliente)"
                    />
                    <p v-if="rejectForm.errors.reason" class="mt-1 text-xs text-red-600">{{ rejectForm.errors.reason }}</p>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button type="button" variant="secondary" @click="closeReject">Cancelar</Button>
                        <Button type="button" :disabled="rejectForm.processing" @click="submitReject">Confirmar recusa</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
