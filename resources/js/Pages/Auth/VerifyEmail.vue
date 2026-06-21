<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import Button from '@/components/ui/Button.vue';
import { Mail } from 'lucide-vue-next';
import { useAuthBranding } from '@/composables/useAuthBranding';

const props = defineProps({
    email: { type: String, required: true },
    resend_available_in_seconds: { type: Number, default: 0 },
    resend_cooldown_seconds: { type: Number, default: 60 },
});

const page = usePage();
const { primary } = useAuthBranding();

const resendForm = useForm({});
const countdown = ref(Math.max(0, Number(props.resend_available_in_seconds) || 0));
let countdownTimer = null;

const canResend = computed(() => countdown.value <= 0 && !resendForm.processing);

const resendButtonLabel = computed(() => {
    if (resendForm.processing) {
        return 'Enviando…';
    }
    if (countdown.value > 0) {
        return `Reenviar em ${countdown.value}s`;
    }
    return 'Reenviar e-mail de confirmação';
});

function startCountdown(seconds) {
    countdown.value = Math.max(0, Number(seconds) || 0);
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
    if (countdown.value <= 0) {
        return;
    }
    countdownTimer = setInterval(() => {
        if (countdown.value > 0) {
            countdown.value -= 1;
        }
        if (countdown.value <= 0 && countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }, 1000);
}

function submitResend() {
    if (!canResend.value) {
        return;
    }
    resendForm.post('/email/verificacao/reenviar', {
        preserveScroll: true,
        onSuccess: () => {
            startCountdown(props.resend_cooldown_seconds);
        },
    });
}

onMounted(() => {
    startCountdown(props.resend_available_in_seconds);
});

onBeforeUnmount(() => {
    if (countdownTimer) {
        clearInterval(countdownTimer);
    }
});

const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
</script>

<template>
    <AuthPageShell title="Confirme seu e-mail" subtitle="Enviamos um link de confirmação para o endereço abaixo." variant="seller">
        <div class="mt-8 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/60">
            <div class="flex flex-col items-center text-center">
                <div
                    class="flex h-14 w-14 items-center justify-center rounded-full"
                    :style="{ backgroundColor: primary + '22' }"
                >
                    <Mail class="h-7 w-7" :style="{ color: primary }" />
                </div>
                <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                    Abra o e-mail enviado para
                </p>
                <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ email }}</p>
                <p class="mt-3 text-sm text-zinc-500">
                    Clique no botão de confirmação no e-mail para liberar o seu acesso.
                </p>
            </div>

            <p
                v-if="flashSuccess"
                class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-100"
            >
                {{ flashSuccess }}
            </p>
            <p
                v-if="flashError"
                class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200"
            >
                {{ flashError }}
            </p>

            <form class="mt-6 flex flex-col gap-3" @submit.prevent="submitResend">
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full"
                    :disabled="!canResend"
                >
                    {{ resendButtonLabel }}
                </Button>
                <p class="text-center text-xs text-zinc-500">
                    Por segurança, aguarde {{ resend_cooldown_seconds }} segundos entre cada reenvio.
                    Máximo de 5 solicitações por hora.
                </p>
            </form>

            <p class="mt-4 text-center text-sm text-zinc-500">
                <Link href="/logout" method="post" as="button" class="font-medium text-[var(--color-primary)] hover:underline">
                    Sair e usar outro e-mail
                </Link>
            </p>
        </div>
    </AuthPageShell>
</template>
