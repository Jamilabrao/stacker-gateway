<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { htmlToText } from '@/lib/sanitizeHtml';

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    withdrawals: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ withdrawal_status: 'all' }),
    },
    /** Gateway de saque ativo (ex.: cajupay) — usado para exibir reprocessamento */
    payout_gateway_active: {
        type: String,
        default: '',
    },
    /** PIN obrigatório quando o 2FA do admin está desligado */
    require_manual_approval_pin: {
        type: Boolean,
        default: false,
    },
    has_manual_approval_pin: {
        type: Boolean,
        default: false,
    },
});

const withdrawalFilterChips = [
    { withdrawal_status: 'all', label: 'Todos os saques' },
    { withdrawal_status: 'pending', label: 'Pendente' },
    { withdrawal_status: 'paid', label: 'Aprovado' },
    { withdrawal_status: 'failed', label: 'Falhou (estornado)' },
    { withdrawal_status: 'rejected', label: 'Rejeitado' },
];

const originFilterChips = [
    { origin: 'all', label: 'Todas origens' },
    { origin: 'api', label: 'Origem API' },
];

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
const stepUpAction = ref(null);
const stepUpWithdrawalId = ref(null);
const stepUpManual = ref(false);
const stepUpRequirePin = ref(false);
const stepUpHasExternalPayout = ref(false);

function selectWithdrawalFilter(withdrawalStatus) {
    router.get(
        '/plataforma/saques',
        {
            withdrawal_status: withdrawalStatus,
            origin: props.filters?.origin && props.filters.origin !== 'all' ? props.filters.origin : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function selectOriginFilter(origin) {
    router.get(
        '/plataforma/saques',
        {
            withdrawal_status: props.filters?.withdrawal_status ?? 'all',
            origin: origin === 'all' ? undefined : origin,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function originChipIsActive(origin) {
    return (props.filters?.origin ?? 'all') === origin;
}

function withdrawalChipIsActive(ws) {
    return (props.filters?.withdrawal_status ?? 'all') === ws;
}

function bucketLabel(b) {
    const map = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto' };
    return map[b] || b || '—';
}

function openApproveStepUp(id, manual = false) {
    stepUpWithdrawalId.value = id;
    stepUpManual.value = manual;
    stepUpAction.value = 'approve';
    stepUpRequirePin.value = Boolean(props.require_manual_approval_pin) && Boolean(props.has_manual_approval_pin);
    stepUpOpen.value = true;
}

function openRejectStepUp(id, hasExternalPayout = false) {
    stepUpWithdrawalId.value = id;
    stepUpAction.value = 'reject';
    stepUpManual.value = false;
    stepUpRequirePin.value = false;
    stepUpHasExternalPayout.value = hasExternalPayout;
    stepUpOpen.value = true;
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
}

const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const payoutBarrierMissing = computed(
    () => !totpEnabled.value && !Boolean(props.has_manual_approval_pin)
);

const stepUpDescription = computed(() => {
    if (stepUpAction.value === 'reject' && stepUpHasExternalPayout.value) {
        return totpEnabled.value
            ? 'Informe o código 2FA. Se o PIX já foi liquidado no gateway, o estorno local não reverte o pagamento bancário.'
            : 'Se o PIX já foi liquidado no gateway, o estorno local não reverte o pagamento bancário.';
    }
    if (stepUpAction.value === 'reject') {
        return totpEnabled.value
            ? 'Informe o código 2FA para autorizar esta ação.'
            : 'Confirme o cancelamento. O saldo será devolvido ao infoprodutor.';
    }
    if (payoutBarrierMissing.value) {
        return 'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques. Pelo menos uma barreira é obrigatória para pagar saques.';
    }
    if (stepUpManual.value) {
        return totpEnabled.value
            ? 'Confirme com 2FA que o PIX já foi enviado fora do sistema.'
            : 'Informe o PIN de operação e confirme que o PIX já foi enviado fora do sistema.';
    }
    if (stepUpRequirePin.value) {
        return 'Informe o PIN de operação cadastrado em Financeiro > Saques.';
    }
    return 'Informe o código 2FA para autorizar esta ação.';
});

function onStepUpConfirm(payload) {
    const id = stepUpWithdrawalId.value;
    if (!id) return;
    stepUpLoading.value = true;

    if (stepUpAction.value === 'approve') {
        if (payoutBarrierMissing.value) {
            stepUpLoading.value = false;
            return;
        }
        router.post(
            `/plataforma/financeiro/saques/${id}/aprovar`,
            {
                payout_manual: stepUpManual.value,
                totp_code: payload.totp_code,
                manual_approval_pin: payload.manual_approval_pin,
                manual_confirm_external: payload.manual_confirm_external,
            },
            {
                preserveScroll: true,
                onFinish: () => closeStepUp(),
            }
        );
        return;
    }

    const note =
        window.prompt(
            stepUpHasExternalPayout.value
                ? 'Motivo do cancelamento (opcional). O saldo será devolvido ao infoprodutor.\n\nAtenção: se o PIX já foi liquidado na CajuPay, o estorno local não reverte o pagamento bancário.'
                : 'Motivo do cancelamento (opcional). O saldo será devolvido ao infoprodutor.'
        ) || '';
    router.post(
        `/plataforma/financeiro/saques/${id}/rejeitar`,
        {
            admin_note: note,
            totp_code: payload.totp_code,
            manual_approval_pin: payload.manual_approval_pin,
        },
        {
            preserveScroll: true,
            onFinish: () => closeStepUp(),
        }
    );
}

function reprocessCajuPayWithdrawal(id) {
    if (
        !confirm(
            'Tentar enviar novamente este saque via CajuPay (mesmo valor e chave PIX cadastrada)? Use quando já houver saldo na conta CajuPay.'
        )
    ) {
        return;
    }
    router.post(`/plataforma/financeiro/saques/${id}/reprocessar-cajupay`, {}, { preserveScroll: true });
}

function reconcileCajuPayWithdrawal(id) {
    router.post(`/plataforma/financeiro/saques/${id}/reconciliar-cajupay`, {}, { preserveScroll: true });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function withdrawalStatusLabel(status) {
    const map = {
        pending: 'Pendente',
        processing: 'Processando',
        paid: 'Aprovado',
        rejected: 'Rejeitado',
        failed: 'Falhou (estornado)',
    };
    return map[status] ?? status ?? '—';
}

function withdrawalAwaitingGateway(w) {
    return w.status === 'pending' && Boolean(w.payout_external_id);
}

function withdrawalStatusBadgeClass(status) {
    if (status === 'paid') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    if (status === 'pending' || status === 'processing') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100';
    if (status === 'failed') return 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200';
    if (status === 'rejected') return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200';
    return 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
}

const withdrawalRows = () => props.withdrawals?.data ?? [];

const paginationLinks = computed(() => props.withdrawals?.links ?? []);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Saques</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Solicitações de saque por status. Aprove, reprocesse ou cancele — o saldo volta ao infoprodutor quando o saque
                é cancelado ou falha no gateway.
            </p>
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

        <div
            class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
        >
            <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
                <div
                    class="inline-flex min-w-full flex-wrap gap-2"
                    role="tablist"
                    aria-label="Filtro de status dos saques"
                >
                    <button
                        v-for="chip in withdrawalFilterChips"
                        :key="chip.withdrawal_status"
                        type="button"
                        role="tab"
                        :aria-selected="withdrawalChipIsActive(chip.withdrawal_status)"
                        :class="[
                            'inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-medium transition',
                            withdrawalChipIsActive(chip.withdrawal_status)
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-200',
                        ]"
                        @click="selectWithdrawalFilter(chip.withdrawal_status)"
                    >
                        {{ chip.label }}
                    </button>
                </div>
            </div>
            <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
                <div class="inline-flex min-w-full flex-wrap gap-2" role="tablist" aria-label="Filtro de origem">
                    <button
                        v-for="chip in originFilterChips"
                        :key="chip.origin"
                        type="button"
                        role="tab"
                        :aria-selected="originChipIsActive(chip.origin)"
                        :class="[
                            'inline-flex items-center gap-2 whitespace-nowrap rounded-lg border px-3 py-2 text-sm font-medium transition',
                            originChipIsActive(chip.origin)
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/50 dark:text-zinc-200',
                        ]"
                        @click="selectOriginFilter(chip.origin)"
                    >
                        {{ chip.label }}
                    </button>
                </div>
            </div>
        </div>

        <section
            class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800"
        >
            <div
                class="mb-4 rounded-xl border border-amber-200 bg-amber-50/90 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <p class="font-medium">Pendentes</p>
                <p class="mt-1 text-sm opacity-95">
                    O envio do PIX costuma ser <strong>automático</strong> ao solicitar no Financeiro. Em
                    <strong>Aguardando gateway</strong>, use <strong>Reconciliar</strong> (consulta a CajuPay) ou
                    <strong>Confirmar pago</strong> se o PIX já liquidou lá. Use <strong>Pago manual</strong> só quando
                    o pagamento foi feito por fora, sem ID no gateway.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                        <tr>
                            <th class="pb-2 pr-3">Data</th>
                            <th class="pb-2 pr-3">ID</th>
                            <th class="pb-2 pr-3">Infoprodutor</th>
                            <th class="pb-2 pr-3">Carteira</th>
                            <th class="pb-2 pr-3 text-right">Bruto</th>
                            <th class="pb-2 pr-3 text-right">Taxa</th>
                            <th class="pb-2 pr-3 text-right">Líquido</th>
                            <th class="pb-2 pr-3">Status</th>
                            <th class="pb-2 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        <template v-for="w in withdrawalRows()" :key="w.id">
                            <tr>
                                <td class="whitespace-nowrap py-3 text-zinc-600 dark:text-zinc-300">
                                    {{ w.created_at ? new Date(w.created_at).toLocaleString('pt-BR') : '—' }}
                                </td>
                                <td class="py-3 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                                    #{{ w.id }}
                                    <span
                                        v-if="w.api_application_id"
                                        class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-sky-800 dark:bg-sky-900/40 dark:text-sky-200"
                                    >
                                        API
                                    </span>
                                </td>
                                <td class="py-3">
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ w.infoprodutor_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ w.infoprodutor_email }}</div>
                                </td>
                                <td class="py-3">{{ bucketLabel(w.bucket) }}</td>
                                <td class="py-3 text-right tabular-nums">{{ formatBRL(w.amount) }}</td>
                                <td class="py-3 text-right tabular-nums text-zinc-500">{{ formatBRL(w.fee_amount) }}</td>
                                <td class="py-3 text-right tabular-nums">{{ formatBRL(w.net_amount) }}</td>
                                <td class="py-3">
                                    <div class="flex flex-col gap-1">
                                        <span
                                            :class="[
                                                'inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium',
                                                withdrawalStatusBadgeClass(w.status),
                                            ]"
                                        >
                                            {{ withdrawalStatusLabel(w.status) }}
                                        </span>
                                        <span
                                            v-if="withdrawalAwaitingGateway(w)"
                                            class="text-[10px] font-medium uppercase tracking-wide text-sky-600 dark:text-sky-400"
                                        >
                                            Aguardando {{ w.payout_provider || 'gateway' }}
                                        </span>
                                        <span
                                            v-if="w.status === 'paid' && w.payout_manual"
                                            class="text-[10px] font-medium uppercase tracking-wide text-violet-600 dark:text-violet-400"
                                        >
                                            Pago manual
                                        </span>
                                    </div>
                                </td>
                                <td class="py-3 text-right">
                                    <div v-if="w.status === 'processing'" class="flex flex-col items-end gap-2">
                                        <p class="text-xs text-amber-700 dark:text-amber-300">Processando envio PIX…</p>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            @click="openRejectStepUp(w.id, Boolean(w.payout_external_id))"
                                        >
                                            Cancelar e estornar
                                        </Button>
                                    </div>
                                    <div
                                        v-else-if="w.status === 'pending' && withdrawalAwaitingGateway(w)"
                                        class="flex flex-col items-end gap-2"
                                    >
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <Button
                                                v-if="w.payout_provider === 'cajupay'"
                                                type="button"
                                                size="sm"
                                                @click="reconcileCajuPayWithdrawal(w.id)"
                                            >
                                                Reconciliar
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                @click="openApproveStepUp(w.id, true)"
                                            >
                                                Confirmar pago
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                @click="openRejectStepUp(w.id, true)"
                                            >
                                                Cancelar e estornar
                                            </Button>
                                        </div>
                                    </div>
                                    <div
                                        v-else-if="w.status === 'pending' && !withdrawalAwaitingGateway(w)"
                                        class="flex flex-wrap justify-end gap-2"
                                    >
                                        <Button
                                            v-if="payout_gateway_active === 'cajupay'"
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            @click="reprocessCajuPayWithdrawal(w.id)"
                                        >
                                            Reprocessar
                                        </Button>
                                        <Button type="button" size="sm" @click="openApproveStepUp(w.id, false)">
                                            Pago (CajuPay)
                                        </Button>
                                        <Button type="button" size="sm" variant="secondary" @click="openApproveStepUp(w.id, true)">
                                            Pago manual
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            @click="openRejectStepUp(w.id, false)"
                                        >
                                            Cancelar e estornar
                                        </Button>
                                    </div>
                                    <div v-else-if="w.can_download_receipt" class="flex justify-end">
                                        <a
                                            :href="`/plataforma/saques/${w.id}/comprovante`"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >
                                            Comprovante
                                        </a>
                                    </div>
                                    <span v-else class="text-xs text-zinc-400">—</span>
                                </td>
                            </tr>
                            <tr
                                v-if="w.status === 'pending' && w.payout_last_error"
                                class="border-t border-red-100 bg-red-50/80 dark:border-red-900/40 dark:bg-red-950/20"
                            >
                                <td colspan="9" class="px-3 py-2 text-xs text-red-900 dark:text-red-200">
                                    <span class="font-medium">Última tentativa (CajuPay):</span>
                                    {{ w.payout_last_error }}
                                    <span v-if="w.payout_last_attempt_at" class="ml-2 text-red-700/80 dark:text-red-300/80">
                                        ({{ new Date(w.payout_last_attempt_at).toLocaleString('pt-BR') }})
                                    </span>
                                </td>
                            </tr>
                            <tr
                                v-if="withdrawalAwaitingGateway(w) && (w.webhook_last_at || w.reconcile_last_at || w.reconcile_exhausted)"
                                class="border-t border-sky-100 bg-sky-50/70 dark:border-sky-900/40 dark:bg-sky-950/20"
                            >
                                <td colspan="9" class="px-3 py-2 text-xs text-sky-950 dark:text-sky-100">
                                    <span v-if="w.payout_external_id" class="mr-3">
                                        <span class="font-medium">ID gateway:</span> {{ w.payout_external_id }}
                                    </span>
                                    <span v-if="w.webhook_last_at" class="mr-3">
                                        <span class="font-medium">Webhook:</span>
                                        {{ w.webhook_last_event || '—' }} / {{ w.webhook_last_status || '—' }}
                                        ({{ new Date(w.webhook_last_at).toLocaleString('pt-BR') }})
                                    </span>
                                    <span v-if="w.reconcile_last_at" class="mr-3">
                                        <span class="font-medium">Reconciliação:</span>
                                        {{ w.reconcile_last_api_status ?? 'null' }}
                                        ({{ new Date(w.reconcile_last_at).toLocaleString('pt-BR') }})
                                    </span>
                                    <span v-if="w.reconcile_exhausted" class="font-medium text-amber-800 dark:text-amber-200">
                                        Tentativas de reconciliação esgotadas — use Reconciliar ou Confirmar pago.
                                    </span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!withdrawalRows().length">
                            <td colspan="9" class="py-8 text-center text-zinc-500">Nenhum saque encontrado.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <nav
            v-if="paginationLinks.length > 3"
            class="flex flex-wrap items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in paginationLinks"
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
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :loading="stepUpLoading"
            :require-totp="totpEnabled"
            :require-pin="stepUpRequirePin && stepUpAction === 'approve'"
            :require-external-confirm="stepUpManual"
            :title="stepUpAction === 'reject' ? 'Cancelar saque' : stepUpManual ? 'Aprovar manualmente' : 'Aprovar saque'"
            :description="stepUpDescription"
            :confirm-label="stepUpAction === 'reject' ? 'Cancelar e estornar' : 'Aprovar'"
            :confirm-disabled="stepUpAction === 'approve' && payoutBarrierMissing"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </div>
</template>
