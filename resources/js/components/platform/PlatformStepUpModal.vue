<script setup>
import { ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirmação de segurança' },
    description: { type: String, default: '' },
    /** When false, hide the 2FA field (platform TOTP not enabled). */
    requireTotp: { type: Boolean, default: true },
    requirePin: { type: Boolean, default: false },
    requireExternalConfirm: { type: Boolean, default: false },
    confirmLabel: { type: String, default: 'Confirmar' },
    loading: { type: Boolean, default: false },
    confirmDisabled: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);

const MANUAL_APPROVAL_PIN_MAX_LENGTH = 6;

const totpCode = ref('');
const manualPin = ref('');
const externalConfirm = ref(false);

function sanitizeManualApprovalPinInput(value) {
    return String(value ?? '').replace(/\D/g, '').slice(0, MANUAL_APPROVAL_PIN_MAX_LENGTH);
}

function onManualPinInput(event) {
    manualPin.value = sanitizeManualApprovalPinInput(event.target.value);
}

watch(
    () => props.open,
    (v) => {
        if (v) {
            totpCode.value = '';
            manualPin.value = '';
            externalConfirm.value = false;
        }
    }
);

function submit() {
    emit('confirm', {
        totp_code: totpCode.value,
        manual_approval_pin: manualPin.value,
        manual_confirm_external: externalConfirm.value,
    });
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-[100002] flex items-center justify-center bg-black/50 p-4"
        @click.self="emit('close')"
    >
        <div
            class="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            role="dialog"
            aria-modal="true"
        >
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ title }}
            </h3>
            <p
                v-if="description"
                class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
            >
                {{ description }}
            </p>

            <div class="mt-4 space-y-3">
                <div v-if="requireTotp">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código 2FA
                    </label>
                    <input
                        v-model="totpCode"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="000000"
                    />
                </div>

                <div v-if="requirePin">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        PIN de operação
                    </label>
                    <input
                        :value="manualPin"
                        type="password"
                        inputmode="numeric"
                        :maxlength="MANUAL_APPROVAL_PIN_MAX_LENGTH"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="PIN da plataforma"
                        @input="onManualPinInput"
                    />
                </div>

                <label
                    v-if="requireExternalConfirm"
                    class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="externalConfirm"
                        type="checkbox"
                        class="mt-1"
                    />
                    <span>Confirmo que o PIX já foi enviado fora do sistema (aprovação manual).</span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <Button type="button" variant="secondary" @click="emit('close')">
                    Cancelar
                </Button>
                <Button type="button" :disabled="loading || confirmDisabled" @click="submit">
                    {{ confirmLabel }}
                </Button>
            </div>
        </div>
    </div>
</template>
