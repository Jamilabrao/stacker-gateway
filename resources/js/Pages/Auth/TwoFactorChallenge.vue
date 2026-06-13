<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Shield, ArrowRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import AuthSpotlightField from '@/components/auth/AuthSpotlightField.vue';
import AuthImmersiveField from '@/components/auth/AuthImmersiveField.vue';
import { useAuthFormStyles } from '@/composables/useAuthFormStyles';

const props = defineProps({
    submitUrl: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    title: { type: String, default: 'Verificação em dois fatores' },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);

const {
    isSpotlight,
    isImmersive,
    primary,
    inputClass,
    labelClass,
    linkClass,
    alertErrorClass,
    submitButtonClass,
} = useAuthFormStyles();

const form = useForm({
    totp_code: '',
});

function submit() {
    form.post(props.submitUrl, {
        preserveScroll: true,
    });
}

function cancel() {
    form.post(props.cancelUrl);
}
</script>

<template>
    <AuthPageShell
        :title="title"
        subtitle="Digite o código de 6 dígitos do seu aplicativo autenticador."
        variant="neutral"
    >
        <div
            v-if="isSpotlight || isImmersive"
            class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl"
            :class="isImmersive ? 'bg-white/10 text-[var(--wl-primary)]' : 'bg-indigo-500/15 text-indigo-300'"
        >
            <Shield class="h-7 w-7" aria-hidden="true" />
        </div>

        <p v-if="flashError" :class="['mb-4', alertErrorClass]">
            {{ flashError }}
        </p>

        <form class="space-y-5" @submit.prevent="submit">
            <template v-if="isImmersive">
                <AuthImmersiveField
                    id="totp_code"
                    v-model="form.totp_code"
                    label="Código 2FA"
                    type="text"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    :error="form.errors.totp_code"
                />
            </template>
            <template v-else-if="isSpotlight">
                <AuthSpotlightField
                    id="totp_code"
                    v-model="form.totp_code"
                    label="Código 2FA"
                    type="text"
                    inputmode="numeric"
                    maxlength="6"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    :error="form.errors.totp_code"
                />
            </template>
            <template v-else>
                <div>
                    <label for="totp_code" :class="labelClass">Código 2FA</label>
                    <input
                        id="totp_code"
                        v-model="form.totp_code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        :class="[inputClass, 'text-center text-lg tracking-[0.35em]']"
                        placeholder="000000"
                    />
                    <p v-if="form.errors.totp_code" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.totp_code }}
                    </p>
                </div>
            </template>

            <Button
                v-if="!isSpotlight && !isImmersive"
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Verificando…' : 'Confirmar e entrar' }}
            </Button>
            <button
                v-else
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
                :style="isImmersive
                    ? { background: primary, color: '#0a0a0a' }
                    : { background: `linear-gradient(135deg, ${primary}, color-mix(in srgb, ${primary} 70%, #a855f7))` }"
            >
                <span class="inline-flex items-center justify-center gap-2">
                    {{ form.processing ? 'Verificando…' : 'Confirmar e entrar' }}
                    <ArrowRight v-if="!form.processing" class="h-4 w-4" />
                </span>
            </button>
        </form>

        <p :class="['mt-6 text-center text-sm', isSpotlight ? 'text-zinc-400' : isImmersive ? 'text-white/55' : 'text-zinc-600 dark:text-zinc-400']">
            <button type="button" :class="linkClass" @click="cancel">
                Voltar ao login
            </button>
        </p>
    </AuthPageShell>
</template>

<style scoped>
.wl-submit {
    background-color: var(--wl-primary) !important;
    color: #18181b !important;
}
</style>
