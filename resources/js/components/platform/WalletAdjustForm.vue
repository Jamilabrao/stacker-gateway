<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';

const props = defineProps({
    userId: { type: Number, required: true },
    redirectTo: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});

const page = usePage();
const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);

const form = useForm({
    amount: '',
    direction: 'credit',
    bucket: 'pix',
    note: '',
});

function resetFormFields() {
    form.reset('amount', 'note');
    form.direction = 'credit';
    form.bucket = 'pix';
}

function postAdjustment(totpCode = '') {
    form.transform((data) => ({
        ...data,
        totp_code: totpCode || undefined,
        redirect_to: props.redirectTo || undefined,
    })).post(`/plataforma/usuarios/${props.userId}/ajuste-saldo`, {
        preserveScroll: true,
        onSuccess: () => {
            resetFormFields();
        },
        onFinish: () => {
            stepUpLoading.value = false;
            stepUpOpen.value = false;
        },
    });
}

function submit() {
    if (platformTotpEnabled.value) {
        stepUpOpen.value = true;
        return;
    }
    postAdjustment();
}

function onStepUpConfirm(payload) {
    stepUpLoading.value = true;
    postAdjustment(payload.totp_code);
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div :class="compact ? 'grid gap-3 sm:grid-cols-2' : 'grid gap-4 sm:grid-cols-2 lg:grid-cols-4'">
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Operação</label>
                <select
                    v-model="form.direction"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="credit">Creditar (+)</option>
                    <option value="debit">Debitar (−)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Valor (R$)</label>
                <input
                    v-model="form.amount"
                    type="number"
                    min="0.01"
                    step="0.01"
                    required
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm tabular-nums dark:border-zinc-600 dark:bg-zinc-900"
                    placeholder="0,00"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Bucket</label>
                <select
                    v-model="form.bucket"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="pix">PIX</option>
                    <option value="card">Cartão</option>
                    <option value="boleto">Boleto</option>
                </select>
            </div>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Motivo (obrigatório)</label>
            <textarea
                v-model="form.note"
                rows="2"
                required
                maxlength="500"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Ex.: correção manual, bônus, estorno administrativo..."
            />
        </div>
        <p v-if="form.errors.amount" class="text-sm text-red-600">{{ form.errors.amount }}</p>
        <p v-if="form.errors.note" class="text-sm text-red-600">{{ form.errors.note }}</p>
        <p v-if="form.errors.direction" class="text-sm text-red-600">{{ form.errors.direction }}</p>
        <p v-if="form.errors.bucket" class="text-sm text-red-600">{{ form.errors.bucket }}</p>
        <p v-if="form.errors.wallet" class="text-sm text-red-600">{{ form.errors.wallet }}</p>
        <p v-if="form.errors.totp_code" class="text-sm text-red-600">{{ form.errors.totp_code }}</p>
        <div class="flex justify-end">
            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Aplicando...' : 'Aplicar ajuste' }}
            </Button>
        </div>
    </form>

    <PlatformStepUpModal
        v-if="platformTotpEnabled"
        :open="stepUpOpen"
        title="Confirmar ajuste de saldo"
        description="Informe o código 2FA para aplicar o ajuste manual na carteira do infoprodutor."
        confirm-label="Aplicar ajuste"
        :loading="stepUpLoading"
        @close="closeStepUp"
        @confirm="onStepUpConfirm"
    />
</template>
