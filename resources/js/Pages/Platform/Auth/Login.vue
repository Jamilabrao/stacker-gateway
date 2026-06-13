<script setup>
import { ref, computed } from 'vue';
import { useForm, Link, usePage, router } from '@inertiajs/vue3';
import { Eye, EyeOff, UserCog, Mail, Lock, ArrowRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import AuthSpotlightField from '@/components/auth/AuthSpotlightField.vue';
import AuthImmersiveField from '@/components/auth/AuthImmersiveField.vue';
import AuthSpotlightFormCard from '@/components/auth/AuthSpotlightFormCard.vue';
import { useAuthFormStyles } from '@/composables/useAuthFormStyles';
import { useLoginTemplate } from '@/composables/useLoginTemplate';

const showPassword = ref(false);
const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);
const demoMode = computed(() => page.props.demo_mode ?? {});

const {
    isSpotlight,
    isImmersive,
    primary,
    inputClass,
    labelClass,
    linkClass,
    mutedTextClass,
    alertErrorClass,
    submitButtonClass,
    checkboxClass,
    demoDividerClass,
} = useAuthFormStyles();

const { isModernLogin } = useLoginTemplate();

const pageTitle = computed(() => (isModernLogin.value ? 'Bem-vindo de volta' : 'Entrar'));
const pageSubtitle = computed(() => (
    isModernLogin.value
        ? 'Acesse o painel de operação da plataforma com segurança.'
        : 'Painel da plataforma'
));

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/plataforma/login', {
        onFinish: () => form.reset('password'),
    });
}

const demoBusy = ref(false);

function loginDemoAdmin() {
    demoBusy.value = true;
    router.post('/demo/login/admin', {}, {
        onFinish: () => { demoBusy.value = false; },
    });
}
</script>

<template>
    <AuthPageShell
        :title="pageTitle"
        :subtitle="pageSubtitle"
        variant="platform"
    >
        <p v-if="flashError" :class="alertErrorClass">
            {{ flashError }}
        </p>

        <form v-if="isImmersive" class="space-y-5" @submit.prevent="submit">
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

            <AuthImmersiveField
                id="password"
                v-model="form.password"
                label="Senha"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                placeholder="••••••••"
                required
                :error="form.errors.password"
            >
                <template #icon>
                    <Lock class="h-4 w-4" />
                </template>
                <template #trailing>
                    <button
                        type="button"
                        class="rounded p-1 text-white/40 hover:text-white/70"
                        @click="showPassword = !showPassword"
                    >
                        <Eye v-if="showPassword" class="h-4 w-4" />
                        <EyeOff v-else class="h-4 w-4" />
                    </button>
                </template>
            </AuthImmersiveField>

            <div class="flex items-center gap-3">
                <input id="remember" v-model="form.remember" type="checkbox" :class="checkboxClass" />
                <label for="remember" :class="mutedTextClass">Lembrar de mim</label>
            </div>

            <button
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
                :style="{ background: primary, color: '#0a0a0a' }"
            >
                <span class="inline-flex w-full items-center justify-center gap-2">
                    {{ form.processing ? 'Entrando…' : 'Entrar na plataforma' }}
                    <ArrowRight v-if="!form.processing" class="h-4 w-4" />
                </span>
            </button>
        </form>

        <AuthSpotlightFormCard v-else-if="isSpotlight">
            <form class="space-y-5" @submit.prevent="submit">
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

                <AuthSpotlightField
                    id="password"
                    v-model="form.password"
                    label="Senha"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                    :error="form.errors.password"
                >
                    <template #icon>
                        <Lock class="h-4 w-4" />
                    </template>
                    <template #trailing>
                        <button
                            type="button"
                            class="rounded p-1 text-zinc-500 hover:text-zinc-300"
                            @click="showPassword = !showPassword"
                        >
                            <Eye v-if="showPassword" class="h-4 w-4" />
                            <EyeOff v-else class="h-4 w-4" />
                        </button>
                    </template>
                </AuthSpotlightField>

                <div class="flex items-center gap-3">
                    <input id="remember" v-model="form.remember" type="checkbox" :class="checkboxClass" />
                    <label for="remember" :class="mutedTextClass">Lembrar de mim</label>
                </div>

                <button
                    type="submit"
                    :class="submitButtonClass"
                    :disabled="form.processing"
                    :style="{ background: primary, color: '#0a0a0a' }"
                >
                    <span class="inline-flex w-full items-center justify-center gap-2">
                        {{ form.processing ? 'Entrando…' : 'Entrar na plataforma' }}
                        <ArrowRight v-if="!form.processing" class="h-4 w-4" />
                    </span>
                </button>
            </form>
        </AuthSpotlightFormCard>

        <form v-else class="space-y-5" @submit.prevent="submit">
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
                <div>
                    <label for="password" :class="labelClass">Senha</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            required
                            class="wl-input block w-full rounded-xl border border-zinc-300 bg-white py-3 pl-4 pr-12 text-zinc-900 placeholder-zinc-500 shadow-sm transition dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            placeholder="••••••••"
                        />
                        <button
                            type="button"
                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1.5 text-zinc-500"
                            @click="showPassword = !showPassword"
                        >
                            <Eye v-if="showPassword" class="h-5 w-5" />
                            <EyeOff v-else class="h-5 w-5" />
                        </button>
                    </div>
                    <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.password }}</p>
                </div>

            <div class="flex items-center gap-3">
                <input id="remember" v-model="form.remember" type="checkbox" :class="checkboxClass" />
                <label for="remember" :class="mutedTextClass">Lembrar de mim</label>
            </div>

            <Button
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Entrando…' : 'Entrar' }}
            </Button>
        </form>

        <div
            v-if="demoMode.enabled"
            class="mt-8 space-y-3 border-t pt-6"
            :class="demoDividerClass"
        >
            <p class="text-center text-xs font-medium uppercase tracking-wide text-zinc-500">Demonstração</p>
            <Button
                type="button"
                variant="outline"
                class="w-full"
                :disabled="demoBusy || !demoMode.admin_label"
                @click="loginDemoAdmin"
            >
                <UserCog class="mr-2 inline h-4 w-4" />
                Entrar como Admin
            </Button>
        </div>

        <p class="mt-6 text-center">
            <Link href="/login" :class="linkClass">
                Acesso infoprodutor
            </Link>
        </p>
    </AuthPageShell>
</template>

<style scoped>
.wl-root {
    --wl-primary: v-bind(primary);
}
.wl-input:hover {
    border-color: color-mix(in srgb, var(--wl-primary) 45%, var(--tw-border-color, #d4d4d8));
}
.wl-input:focus {
    border-color: var(--wl-primary);
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wl-primary) 35%, transparent);
}
.wl-submit {
    background-color: var(--wl-primary) !important;
    color: #18181b !important;
}
</style>
