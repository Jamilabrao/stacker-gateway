<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Shield, X } from 'lucide-vue-next';

const COOLDOWN_MS = 2 * 24 * 60 * 60 * 1000;

const page = usePage();
const dismissed = ref(false);

const userId = computed(() => page.props.auth?.user?.id ?? null);
const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const showTotpPrompt = computed(() => Boolean(page.props.auth?.user?.show_totp_prompt));
const needsKycAttention = computed(() => Boolean(page.props.auth?.user?.needs_kyc_attention));
const isPlatformAdmin = computed(() => Boolean(page.props.auth?.is_platform_admin));

const profileHref = computed(() => (
    isPlatformAdmin.value ? '/plataforma/meu-perfil' : '/meu-perfil'
));

const storageKey = computed(() => (
    userId.value ? `totp_prompt_dismissed_${userId.value}` : null
));

function wasDismissedRecently() {
    if (!storageKey.value || typeof localStorage === 'undefined') {
        return false;
    }
    const raw = localStorage.getItem(storageKey.value);
    if (!raw) {
        return false;
    }
    const ts = parseInt(raw, 10);
    return Number.isFinite(ts) && Date.now() - ts < COOLDOWN_MS;
}

function dismiss() {
    if (storageKey.value && typeof localStorage !== 'undefined') {
        localStorage.setItem(storageKey.value, String(Date.now()));
    }
    dismissed.value = true;
}

const show = computed(() => {
    if (dismissed.value || totpEnabled.value || !userId.value || needsKycAttention.value) {
        return false;
    }
    if (!showTotpPrompt.value) {
        return false;
    }
    return !wasDismissedRecently();
});
</script>

<template>
    <div
        v-if="show"
        class="relative border-b border-indigo-200/70 bg-gradient-to-r from-indigo-50 via-emerald-50/80 to-sky-50 px-4 py-3.5 dark:border-indigo-900/40 dark:from-indigo-950/50 dark:via-emerald-950/30 dark:to-sky-950/30"
    >
        <div class="mx-auto flex max-w-[1600px] items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/80 text-indigo-700 shadow-sm dark:bg-zinc-900/70 dark:text-indigo-300">
                <Shield class="h-5 w-5" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                    Proteja sua conta com autenticação em dois fatores
                </p>
                <p class="mt-0.5 hidden text-sm text-zinc-600 sm:block dark:text-zinc-300">
                    Ative o 2FA no seu perfil para exigir um código no login e manter sua conta mais segura.
                </p>
            </div>

            <Link
                :href="profileHref"
                class="shrink-0 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 dark:bg-indigo-600 dark:hover:bg-indigo-500"
            >
                Ativar agora
            </Link>

            <button
                type="button"
                class="shrink-0 rounded-lg p-2 text-zinc-500 transition hover:bg-white/70 hover:text-zinc-800 dark:hover:bg-zinc-800/60 dark:hover:text-zinc-200"
                aria-label="Fechar aviso"
                @click="dismiss"
            >
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
