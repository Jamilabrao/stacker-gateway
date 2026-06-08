<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import QRCode from 'qrcode';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Camera, Lock, Loader2, Shield } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    totp_enabled: { type: Boolean, default: false },
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
        const { data } = await axios.post('/plataforma/seguranca/totp/iniciar', {}, {
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
    totpForm.post('/plataforma/seguranca/totp/confirmar', {
        preserveScroll: true,
        onSuccess: () => {
            totpSetup.value = null;
            totpQrDataUrl.value = '';
            totpForm.reset();
        },
    });
}

function disableTotp() {
    totpDisableForm.post('/plataforma/seguranca/totp/desativar', {
        preserveScroll: true,
        onSuccess: () => totpDisableForm.reset(),
    });
}

const avatarInputRef = ref(null);
const avatarPreview = ref(null);

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
    avatar: null,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const avatarUrl = computed(() => {
    if (avatarPreview.value) return avatarPreview.value;
    return props.user.avatar_url || null;
});

function triggerAvatarClick() {
    avatarInputRef.value?.click();
}

function onAvatarChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    profileForm.avatar = file;
    const reader = new FileReader();
    reader.onload = (e) => { avatarPreview.value = e.target?.result; };
    reader.readAsDataURL(file);
}

function submitProfile() {
    profileForm.post('/plataforma/meu-perfil', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            profileForm.avatar = null;
            avatarPreview.value = null;
        },
    });
}

function submitPassword() {
    passwordForm.put('/plataforma/meu-perfil/senha', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Meu perfil
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Atualize sua foto, nome, e-mail e senha.
            </p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                    <div class="relative shrink-0">
                        <button
                            type="button"
                            class="group relative flex h-28 w-28 overflow-hidden rounded-2xl bg-zinc-100 ring-2 ring-zinc-200 transition-all hover:ring-[var(--color-primary)] dark:bg-zinc-700 dark:ring-zinc-600 dark:hover:ring-[var(--color-primary)]"
                            @click="triggerAvatarClick"
                        >
                            <img
                                v-if="avatarUrl"
                                :src="avatarUrl"
                                alt="Foto de perfil"
                                class="h-full w-full object-cover"
                            />
                            <span
                                v-else
                                class="flex h-full w-full items-center justify-center text-3xl font-semibold text-zinc-400 dark:text-zinc-500"
                            >
                                {{ (user.name || '?').charAt(0).toUpperCase() }}
                            </span>
                            <span class="absolute inset-0 flex items-center justify-center bg-zinc-900/50 opacity-0 transition-opacity group-hover:opacity-100">
                                <Camera class="h-8 w-8 text-white" />
                            </span>
                        </button>
                        <input
                            ref="avatarInputRef"
                            type="file"
                            accept="image/*"
                            class="sr-only"
                            @change="onAvatarChange"
                        />
                        <p v-if="profileForm.errors.avatar" class="mt-2 max-w-xs text-center text-sm text-red-600 dark:text-red-400">
                            {{ profileForm.errors.avatar }}
                        </p>
                    </div>

                    <form class="min-w-0 flex-1 space-y-4" @submit.prevent="submitProfile">
                        <div>
                            <label for="profile-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Nome
                            </label>
                            <input
                                id="profile-name"
                                v-model="profileForm.name"
                                type="text"
                                required
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="Seu nome"
                            />
                            <p v-if="profileForm.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ profileForm.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label for="profile-email" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                E-mail
                            </label>
                            <input
                                id="profile-email"
                                v-model="profileForm.email"
                                type="email"
                                required
                                autocomplete="email"
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="seu@email.com"
                            />
                            <p v-if="profileForm.errors.email" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ profileForm.errors.email }}
                            </p>
                        </div>

                        <Button type="submit" class="w-full sm:w-auto" :disabled="profileForm.processing">
                            <Loader2 v-if="profileForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            Salvar alterações
                        </Button>
                    </form>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8">
                <div class="flex items-center gap-2">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                        <Lock class="h-4 w-4" />
                    </span>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Alterar senha
                    </h2>
                </div>
            </div>

            <form class="space-y-4 p-6 sm:p-8" @submit.prevent="submitPassword">
                <div>
                    <label for="current-password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Senha atual
                    </label>
                    <input
                        id="current-password"
                        v-model="passwordForm.current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Digite sua senha atual"
                    />
                    <p v-if="passwordForm.errors.current_password" class="mt-1 text-sm text-red-600 dark:text-red-400">
                        {{ passwordForm.errors.current_password }}
                    </p>
                </div>

                <div>
                    <label for="new-password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Nova senha
                    </label>
                    <input
                        id="new-password"
                        v-model="passwordForm.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Mínimo 8 caracteres"
                    />
                    <p v-if="passwordForm.errors.password" class="mt-1 text-sm text-red-600 dark:text-red-400">
                        {{ passwordForm.errors.password }}
                    </p>
                </div>

                <div>
                    <label for="confirm-password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Confirmar nova senha
                    </label>
                    <input
                        id="confirm-password"
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Repita a nova senha"
                    />
                </div>

                <Button type="submit" variant="outline" class="w-full sm:w-auto" :disabled="passwordForm.processing">
                    <Loader2 v-if="passwordForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                    Alterar senha
                </Button>
            </form>
        </div>

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
                    2FA ativo. Saques, adquirentes, e-mail e ajustes de saldo exigem código do autenticador.
                </p>
                <p v-else class="text-sm text-zinc-600 dark:text-zinc-400">
                    Ative o Google Authenticator (ou similar) para proteger ações críticas da plataforma.
                </p>

                <div v-if="!totp_enabled && !totpSetup">
                    <Button type="button" :disabled="totpEnrolling" @click="beginTotpEnrollment">
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
    </div>
</template>

