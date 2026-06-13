<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import Button from '@/components/ui/Button.vue';
import { Loader2, Shield } from 'lucide-vue-next';

const props = defineProps({
    totp_enabled: { type: Boolean, default: false },
    beginUrl: { type: String, required: true },
    confirmUrl: { type: String, required: true },
    disableUrl: { type: String, required: true },
    context: {
        type: String,
        default: 'seller',
        validator: (v) => ['platform', 'seller'].includes(v),
    },
});

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        : '';
}

const totpSetup = ref(null);
const totpQrDataUrl = ref('');
const totpQrError = ref('');
const totpEnrolling = ref(false);
const totpForm = useForm({ totp_code: '' });
const totpDisableForm = useForm({ totp_code: '' });

const enabledDescription = computed(() => {
    if (props.context === 'platform') {
        return '2FA ativo. O login e ações críticas (saques, KYC, adquirentes, e-mail e saldos) exigem código do autenticador.';
    }

    return '2FA ativo. Cada novo login exigirá o código do seu aplicativo autenticador.';
});

const disabledDescription = computed(() => {
    if (props.context === 'platform') {
        return 'Ative o Google Authenticator (ou similar) para proteger o login e ações críticas da plataforma.';
    }

    return 'Ative o Google Authenticator (ou similar) para proteger o acesso à sua conta no login.';
});

async function renderTotpQr(setup) {
    totpQrDataUrl.value = '';
    totpQrError.value = '';
    if (!setup?.otpauth_url) {
        return;
    }
    try {
        totpQrDataUrl.value = await QRCode.toDataURL(setup.otpauth_url, {
            width: 220,
            margin: 2,
            errorCorrectionLevel: 'M',
        });
    } catch {
        totpQrError.value = 'Não foi possível gerar o QR code. Use a chave manual abaixo.';
    }
}

watch(totpSetup, (setup) => {
    renderTotpQr(setup);
});

async function beginTotpEnrollment() {
    totpEnrolling.value = true;
    totpQrDataUrl.value = '';
    totpQrError.value = '';
    try {
        const { data } = await axios.post(props.beginUrl, {}, {
            headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
        });
        totpSetup.value = data;
        await renderTotpQr(data);
    } catch {
        window.alert('Não foi possível iniciar a configuração do 2FA.');
    } finally {
        totpEnrolling.value = false;
    }
}

function confirmTotp() {
    totpForm.post(props.confirmUrl, {
        preserveScroll: true,
        onSuccess: () => {
            totpSetup.value = null;
            totpQrDataUrl.value = '';
            totpForm.reset();
        },
    });
}

function disableTotp() {
    totpDisableForm.post(props.disableUrl, {
        preserveScroll: true,
        onSuccess: () => totpDisableForm.reset(),
    });
}
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8">
            <div class="flex items-center gap-2">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                    <Shield class="h-4 w-4" />
                </span>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Autenticação em dois fatores (2FA)
                </h2>
            </div>
        </div>
        <div class="space-y-4 p-6 sm:p-8">
            <p v-if="totp_enabled" class="text-sm text-emerald-700 dark:text-emerald-300">
                {{ enabledDescription }}
            </p>
            <p v-else class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ disabledDescription }}
            </p>

            <div v-if="!totp_enabled && !totpSetup">
                <Button type="button" :disabled="totpEnrolling" @click="beginTotpEnrollment">
                    <Loader2 v-if="totpEnrolling" class="mr-2 h-4 w-4 animate-spin" />
                    Configurar 2FA
                </Button>
            </div>

            <div v-if="totpSetup" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-600">
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    Escaneie o QR code com Google Authenticator, Microsoft Authenticator ou similar.
                </p>

                <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
                    <div
                        class="flex h-[13.75rem] w-[13.75rem] shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-900"
                    >
                        <img
                            v-if="totpQrDataUrl"
                            :src="totpQrDataUrl"
                            alt="QR code para configurar autenticação em dois fatores"
                            class="h-[12.5rem] w-[12.5rem] rounded-lg"
                        />
                        <Loader2
                            v-else-if="!totpQrError"
                            class="h-8 w-8 animate-spin text-zinc-400"
                        />
                        <p v-else class="px-2 text-center text-xs text-amber-700 dark:text-amber-300">
                            {{ totpQrError }}
                        </p>
                    </div>

                    <div class="min-w-0 flex-1 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <p>
                            Se não puder escanear, informe a chave manualmente no app:
                        </p>
                        <code
                            class="block break-all rounded-lg bg-zinc-100 px-3 py-2 text-xs font-mono text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            {{ totpSetup.secret }}
                        </code>
                        <p class="text-xs">
                            Depois de adicionar a conta, digite o código de 6 dígitos gerado pelo app para concluir a ativação.
                        </p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código do autenticador
                    </label>
                    <input
                        v-model="totpForm.totp_code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        class="w-full max-w-xs rounded-xl border border-zinc-300 px-4 py-2.5 text-sm tracking-widest dark:border-zinc-600 dark:bg-zinc-800"
                    />
                    <p v-if="totpForm.errors.totp_code" class="mt-1 text-sm text-red-600 dark:text-red-400">
                        {{ totpForm.errors.totp_code }}
                    </p>
                </div>
                <Button type="button" :disabled="totpForm.processing" @click="confirmTotp">
                    Confirmar e ativar 2FA
                </Button>
            </div>

            <form v-if="totp_enabled" class="flex flex-wrap items-end gap-3" @submit.prevent="disableTotp">
                <div class="min-w-[12rem] flex-1">
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Código para desativar</label>
                    <input
                        v-model="totpDisableForm.totp_code"
                        type="text"
                        maxlength="6"
                        class="mt-1 w-full rounded-xl border border-zinc-300 px-4 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                    />
                </div>
                <Button type="submit" variant="outline" :disabled="totpDisableForm.processing">
                    Desativar 2FA
                </Button>
            </form>
        </div>
    </div>
</template>
