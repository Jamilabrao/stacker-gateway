<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { htmlToText } from '@/lib/sanitizeHtml';

const props = defineProps({
    merchantId: { type: Number, required: true },
    walletTransactions: { type: Object, default: null },
    filters: { type: Object, default: null },
    typeLabels: { type: Object, default: () => ({}) },
    hasManualApprovalPin: { type: Boolean, default: false },
});

const page = usePage();
const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const barrierMissing = computed(() => !totpEnabled.value && !props.hasManualApprovalPin);
const requirePin = computed(() => !totpEnabled.value && props.hasManualApprovalPin);
const stepUpError = computed(() => page.props.errors?.totp_code ?? null);

const typeFilter = ref(props.filters?.wallet_type ?? 'all');
const searchQ = ref(props.filters?.wallet_q ?? '');
const dateFrom = ref(props.filters?.wallet_date_from ?? '');
const dateTo = ref(props.filters?.wallet_date_to ?? '');
const perPage = ref(String(props.filters?.wallet_per_page ?? 25));
const sort = ref(props.filters?.wallet_sort ?? 'id');
const direction = ref(props.filters?.wallet_direction ?? 'desc');

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
const pendingTx = ref(null);

watch(
    () => props.filters,
    (f) => {
        typeFilter.value = f?.wallet_type ?? 'all';
        searchQ.value = f?.wallet_q ?? '';
        dateFrom.value = f?.wallet_date_from ?? '';
        dateTo.value = f?.wallet_date_to ?? '';
        perPage.value = String(f?.wallet_per_page ?? 25);
        sort.value = f?.wallet_sort ?? 'id';
        direction.value = f?.wallet_direction ?? 'desc';
    },
    { deep: true }
);

const rows = computed(() => props.walletTransactions?.data ?? []);
const hasFilters = computed(() => {
    const f = props.filters || {};
    return !!(
        (f.wallet_type && f.wallet_type !== 'all') ||
        f.wallet_q ||
        f.wallet_date_from ||
        f.wallet_date_to
    );
});

const rangeLabel = computed(() => {
    const p = props.walletTransactions;
    if (!p || !p.total) return null;
    return `Exibindo ${p.from ?? 0}–${p.to ?? 0} de ${p.total} movimentações`;
});

const typeOptions = computed(() =>
    Object.entries(props.typeLabels || {}).map(([value, label]) => ({ value, label }))
);

const stepUpDescription = computed(() => {
    if (barrierMissing.value) {
        return 'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para antecipar saldo.';
    }
    const amount = pendingTx.value ? formatBRL(pendingTx.value.amount_net) : '';
    if (totpEnabled.value) {
        return `Informe o código 2FA para liberar ${amount} na carteira do infoprodutor.`;
    }
    return `Informe o PIN de operação para liberar ${amount} na carteira do infoprodutor.`;
});

function visitParams(extra = {}) {
    return {
        tab: 'wallet',
        wallet_type: typeFilter.value !== 'all' ? typeFilter.value : undefined,
        wallet_q: searchQ.value?.trim() || undefined,
        wallet_date_from: dateFrom.value || undefined,
        wallet_date_to: dateTo.value || undefined,
        wallet_per_page: Number(perPage.value) || 25,
        wallet_sort: sort.value,
        wallet_direction: direction.value,
        wallet_page: 1,
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
        direction.value = 'desc';
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

function formatSettlementDate(iso) {
    if (!iso) return 'A confirmar';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return 'A confirmar';
    return d.toLocaleDateString('pt-BR');
}

function settlementLabel(t) {
    if (t.settlement_status === 'available') return 'Disponível';
    if (t.settlement_status === 'pending') return formatSettlementDate(t.settlement_at);
    return '—';
}

function settlementTitle(t) {
    if (t.settlement_status === 'available') {
        return t.released_at
            ? `Liberado em ${formatDate(t.released_at)}`
            : 'Já disponível na carteira do seller';
    }
    if (t.settlement_status === 'pending' && t.settlement_at) {
        return `Previsto para ${formatDate(t.settlement_at)}`;
    }
    if (t.settlement_status === 'pending') {
        return 'Data de liquidação a confirmar';
    }
    return '';
}

function settlementClass(t) {
    if (t.settlement_status === 'available') return 'text-emerald-700 dark:text-emerald-300';
    if (t.settlement_status === 'pending') return 'text-amber-700 dark:text-amber-300';
    return 'text-zinc-500 dark:text-zinc-400';
}

function bucketLabel(b) {
    const map = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto' };
    return map[b] || b || '—';
}

function amountClass(n) {
    const v = Number(n) || 0;
    if (v > 0) return 'text-emerald-700 dark:text-emerald-300';
    if (v < 0) return 'text-red-600 dark:text-red-400';
    return 'text-zinc-600 dark:text-zinc-400';
}

function openAnticipate(t) {
    pendingTx.value = t;
    stepUpOpen.value = true;
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
    pendingTx.value = null;
}

function onStepUpConfirm(payload) {
    const tx = pendingTx.value;
    if (!tx || barrierMissing.value) {
        return;
    }
    stepUpLoading.value = true;
    router.post(
        `/plataforma/usuarios/${props.merchantId}/carteira/transacoes/${tx.id}/antecipar`,
        {
            totp_code: payload.totp_code || undefined,
            manual_approval_pin: payload.manual_approval_pin || undefined,
            wallet_type: typeFilter.value !== 'all' ? typeFilter.value : undefined,
            wallet_q: searchQ.value?.trim() || undefined,
            wallet_date_from: dateFrom.value || undefined,
            wallet_date_to: dateTo.value || undefined,
            wallet_per_page: Number(perPage.value) || 25,
            wallet_sort: sort.value,
            wallet_direction: direction.value,
            wallet_page: props.walletTransactions?.current_page || undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeStepUp(),
            onError: () => {
                stepUpLoading.value = false;
            },
            onFinish: () => {
                stepUpLoading.value = false;
            },
        }
    );
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Movimentações da carteira</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Extrato oficial do ledger deste infoprodutor. Vendas em liquidação podem ser antecipadas com 2FA ou PIN.
            </p>
        </div>

        <form
            class="flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <select
                    v-model="typeFilter"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="all">Tipo: todos</option>
                    <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="ID, pedido ou saque"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <input
                    v-model="dateFrom"
                    type="date"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <input
                    v-model="dateTo"
                    type="date"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <select
                    v-model="perPage"
                    class="rounded-xl border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="25">25 / página</option>
                    <option value="50">50 / página</option>
                    <option value="100">100 / página</option>
                </select>
                <Button type="submit">Filtrar</Button>
            </div>
        </form>

        <p v-if="rangeLabel" class="text-sm text-zinc-600 dark:text-zinc-400">{{ rangeLabel }}</p>
        <p v-if="stepUpError" class="text-sm text-red-600">{{ stepUpError }}</p>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="overflow-x-auto">
                <table class="min-w-[1020px] w-full text-left text-sm">
                    <thead class="border-b border-zinc-100 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-3">
                                <button type="button" class="hover:underline" @click="changeSort('created_at')">Data</button>
                            </th>
                            <th class="px-4 py-3">
                                <button type="button" class="hover:underline" @click="changeSort('type')">Tipo</button>
                            </th>
                            <th class="px-4 py-3">Canal</th>
                            <th class="px-4 py-3 text-right">
                                <button type="button" class="hover:underline" @click="changeSort('amount_net')">Líquido</button>
                            </th>
                            <th class="px-4 py-3" title="Quando o valor entra no saldo disponível da carteira do seller">
                                Liquidação
                            </th>
                            <th class="px-4 py-3">Ref.</th>
                            <th class="px-4 py-3">Obs.</th>
                            <th class="px-4 py-3 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!rows.length">
                            <td colspan="8" class="px-4 py-8 text-center text-zinc-500">
                                {{
                                    hasFilters
                                        ? 'Nenhuma movimentação encontrada no período selecionado.'
                                        : 'Nenhuma movimentação encontrada.'
                                }}
                            </td>
                        </tr>
                        <tr
                            v-for="t in rows"
                            :key="t.id"
                            class="border-b border-zinc-50 dark:border-zinc-800"
                        >
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                {{ formatDate(t.created_at) }}
                            </td>
                            <td class="px-4 py-3">{{ t.type_label }}</td>
                            <td class="px-4 py-3">{{ bucketLabel(t.bucket) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="amountClass(t.amount_net)">
                                {{ formatBRL(t.amount_net) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap" :class="settlementClass(t)" :title="settlementTitle(t)">
                                {{ settlementLabel(t) }}
                            </td>
                            <td class="px-4 py-3 text-xs text-zinc-500">
                                <span v-if="t.order_id">Pedido #{{ t.order_id }}</span>
                                <span v-else-if="t.withdrawal_id">Saque #{{ t.withdrawal_id }}</span>
                                <span v-else>—</span>
                            </td>
                            <td class="max-w-[220px] truncate px-4 py-3 text-xs text-zinc-500" :title="t.note || ''">
                                {{ t.note || '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="t.can_anticipate"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="openAnticipate(t)"
                                >
                                    Antecipar
                                </Button>
                                <span v-else class="text-xs text-zinc-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav v-if="(walletTransactions?.links?.length ?? 0) > 3" class="flex flex-wrap justify-center gap-2">
            <a
                v-for="link in walletTransactions.links"
                :key="link.label + String(link.url)"
                :href="link.url || undefined"
                class="rounded-lg px-3 py-2 text-sm"
                :class="link.active ? 'bg-[var(--color-primary)] text-white' : 'hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                v-text="htmlToText(link.label)"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>

        <PlatformStepUpModal
            :open="stepUpOpen"
            title="Antecipar saldo em liquidação"
            :description="stepUpDescription"
            confirm-label="Antecipar saldo"
            :loading="stepUpLoading"
            :require-totp="totpEnabled"
            :require-pin="requirePin"
            :confirm-disabled="barrierMissing"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </div>
</template>
