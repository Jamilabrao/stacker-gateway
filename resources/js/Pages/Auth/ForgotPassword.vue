<script setup>
import { computed } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import AuthSpotlightField from '@/components/auth/AuthSpotlightField.vue';
import AuthImmersiveField from '@/components/auth/AuthImmersiveField.vue';
import { useAuthFormStyles } from '@/composables/useAuthFormStyles';

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);

const {
    isSpotlight,
    isImmersive,
    primary,
    inputClass,
    labelClass,
    linkClass,
    mutedTextClass,
    submitButtonClass,
} = useAuthFormStyles();

const form = useForm({
    email: '',
});

function submit() {
    form.post('/esqueci-senha', {
        preserveScroll: true,
        onFinish: () => form.reset('email'),
    });
}
</script>

<template>
    <AuthPageShell
        title="Recuperar senha"
        subtitle="Informe seu e-mail para receber o link de redefinição."
        variant="neutral"
    >
        <div
            v-if="status"
            class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300"
            :class="isSpotlight || isImmersive ? '!bg-emerald-500/10 !text-emerald-300' : ''"
        >
            {{ status }}
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <template v-if="isImmersive">
                <AuthImmersiveField
                    id="email"
                    v-model="form.email"
                    label="E-mail"
                    type="email"
                    autocomplete="email"
                    placeholder="seu@email.com"
                    required
                    :error="form.errors.email"
                >
                    <template #icon>
                        <Mail class="h-4 w-4" />
                    </template>
                </AuthImmersiveField>
            </template>
            <template v-else-if="isSpotlight">
                <AuthSpotlightField
                    id="email"
                    v-model="form.email"
                    label="E-mail"
                    type="email"
                    autocomplete="email"
                    placeholder="seu@email.com"
                    required
                    :error="form.errors.email"
                >
                    <template #icon>
                        <Mail class="h-4 w-4" />
                    </template>
                </AuthSpotlightField>
            </template>
            <template v-else>
                <div>
                    <label for="email" :class="labelClass">E-mail</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        :class="inputClass"
                        placeholder="seu@email.com"
                    />
                    <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
                </div>
            </template>

            <button
                v-if="isSpotlight || isImmersive"
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
                :style="{ background: primary, color: '#0a0a0a' }"
            >
                {{ form.processing ? 'Enviando…' : 'Enviar link' }}
            </button>
            <Button v-else type="submit" :class="submitButtonClass" :disabled="form.processing">
                {{ form.processing ? 'Enviando…' : 'Enviar link' }}
            </Button>
        </form>

        <p :class="['mt-6 text-center', mutedTextClass]">
            <Link href="/login" :class="linkClass">Voltar ao login</Link>
        </p>
    </AuthPageShell>
</template>

<style scoped>
.wl-submit {
    background-color: v-bind(primary) !important;
    color: #18181b !important;
}
</style>
