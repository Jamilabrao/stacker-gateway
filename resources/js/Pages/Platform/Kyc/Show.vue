<script setup>
import { ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    merchant: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
    platform_totp_enabled: { type: Boolean, default: false },
});

const rejectForm = useForm({
    reason: '',
});

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
const pendingAction = ref(null);

function kindLabel(k) {
    const m = {
        rg_front: 'RG — frente',
        rg_back: 'RG — verso',
        company_document: 'CNPJ ou contrato social',
        cnpj_card: 'Cartão CNPJ',
        social_contract: 'Contrato social',
    };
    return m[k] || k;
}

function revenueLabel(v) {
    const m = {
        up_to_10k: 'Até R$ 10 mil',
        '10k_50k': 'R$ 10 mil a R$ 50 mil',
        '50k_100k': 'R$ 50 mil a R$ 100 mil',
        '100k_500k': 'R$ 100 mil a R$ 500 mil',
        over_500k: 'Acima de R$ 500 mil',
    };
    return m[v] || v || '—';
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
    pendingAction.value = null;
}

function kycActionBase() {
    return `/plataforma/verificacoes-kyc/usuario/${props.merchant.id}`;
}

function submitApprove() {
    stepUpLoading.value = true;
    router.post(`${kycActionBase()}/aprovar`, {}, {
        preserveScroll: true,
        onFinish: () => {
            stepUpLoading.value = false;
        },
    });
}

function submitRejectDirect() {
    rejectForm.post(`${kycActionBase()}/rejeitar`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset('reason'),
    });
}

function approve() {
    if (!confirm('Aprovar a verificação deste infoprodutor?')) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'approve';
        stepUpOpen.value = true;
        return;
    }
    submitApprove();
}

function submitReject() {
    if (!rejectForm.reason?.trim()) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'reject';
        stepUpOpen.value = true;
        return;
    }
    submitRejectDirect();
}

function onStepUpConfirm(payload) {
    stepUpLoading.value = true;

    if (pendingAction.value === 'approve') {
        router.post(
            `${kycActionBase()}/aprovar`,
            { totp_code: payload.totp_code },
            {
                preserveScroll: true,
                onFinish: closeStepUp,
            },
        );
        return;
    }

    rejectForm
        .transform((data) => ({
            ...data,
            totp_code: payload.totp_code,
        }))
        .post(`${kycActionBase()}/rejeitar`, {
            preserveScroll: true,
            onSuccess: () => rejectForm.reset('reason'),
            onFinish: closeStepUp,
        });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-4">
            <Link href="/plataforma/verificacoes-kyc" class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">← Voltar à lista</Link>
        </div>

        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ merchant.name }}</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ merchant.email }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Cadastro</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tipo</dt>
                        <dd>{{ merchant.person_type === 'pj' ? 'Pessoa jurídica' : 'Pessoa física' }}</dd>
                    </div>
                    <div v-if="merchant.company_name" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Razão social</dt>
                        <dd class="text-right">{{ merchant.company_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Documento</dt>
                        <dd class="font-mono text-xs">{{ merchant.document }}</dd>
                    </div>
                    <div v-if="merchant.legal_representative_cpf" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">CPF representante</dt>
                        <dd class="font-mono text-xs">{{ merchant.legal_representative_cpf }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Nascimento</dt>
                        <dd>{{ merchant.birth_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Faturamento mensal (faixa)</dt>
                        <dd>{{ revenueLabel(merchant.monthly_revenue_range) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Endereço</h2>
                <p class="mt-3 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                    {{ merchant.address_street }}, {{ merchant.address_number }}
                    <span v-if="merchant.address_complement"> — {{ merchant.address_complement }}</span>
                    <br />
                    {{ merchant.address_neighborhood }} — {{ merchant.address_city }}/{{ merchant.address_state }}
                    <br />
                    CEP {{ merchant.address_zip }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Documentos</h2>
            <ul class="mt-4 space-y-2">
                <li
                    v-for="d in documents"
                    :key="d.public_token || d.id"
                    class="flex flex-col gap-2 rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-800/40"
                >
                    <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ kindLabel(d.kind) }}</span>
                    <a
                        :href="d.download_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-[var(--color-primary)] px-3 py-2 text-center text-sm font-medium text-white hover:opacity-90 sm:w-auto sm:bg-transparent sm:px-0 sm:py-0 sm:text-[var(--color-primary)] sm:hover:underline"
                    >
                        Abrir / baixar
                    </a>
                </li>
            </ul>
            <p v-if="!documents.length" class="mt-2 text-sm text-zinc-500">Nenhum arquivo enviado.</p>
        </div>

        <div v-if="merchant.kyc_rejection_reason" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            <strong>Motivo da última rejeição:</strong>
            {{ merchant.kyc_rejection_reason }}
        </div>

        <div v-if="merchant.kyc_status === 'pending_review'" class="flex flex-col gap-4 rounded-2xl border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <p class="text-sm text-amber-950 dark:text-amber-100">Esta conta está aguardando sua decisão.</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Button type="button" class="w-full bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto" @click="approve">
                    Aprovar
                </Button>
            </div>
            <form class="space-y-2 border-t border-amber-200 pt-4 dark:border-amber-800" @submit.prevent="submitReject">
                <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Rejeitar (informe o motivo)</label>
                <textarea
                    v-model="rejectForm.reason"
                    required
                    rows="3"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <p v-if="rejectForm.errors.reason" class="text-sm text-red-600">{{ rejectForm.errors.reason }}</p>
                <p v-if="rejectForm.errors.totp_code" class="text-sm text-red-600">{{ rejectForm.errors.totp_code }}</p>
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full border-red-300 text-red-800 hover:bg-red-50 sm:w-auto dark:border-red-800 dark:text-red-200"
                    :disabled="rejectForm.processing"
                >
                    Rejeitar
                </Button>
            </form>
        </div>

        <PlatformStepUpModal
            v-if="platform_totp_enabled"
            :open="stepUpOpen"
            :loading="stepUpLoading"
            :title="pendingAction === 'reject' ? 'Rejeitar verificação KYC' : 'Aprovar verificação KYC'"
            description="Informe o código 2FA do seu perfil de operador."
            :confirm-label="pendingAction === 'reject' ? 'Rejeitar' : 'Aprovar'"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </div>
</template>
