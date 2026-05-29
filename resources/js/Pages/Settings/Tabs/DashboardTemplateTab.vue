<script setup>
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import { LayoutGrid, Sparkles, Heart, Check, Sun, Moon, Monitor } from 'lucide-vue-next';

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const success = ref('');
const selected = ref('default');

const schemeLoading = ref(true);
const schemeSaving = ref(false);
const schemeError = ref('');
const schemeSuccess = ref('');
const schemeUiMode = ref('auto');
const schemeTheme = ref('dark');

const options = [
    {
        id: 'default',
        label: 'Padrão',
        description: 'Layout atual do painel: sidebar recolhível e cards simples.',
    },
    {
        id: 'aurora',
        label: 'Aurora',
        description: 'Visual moderno com sidebar ampla, fundo em mesh e cards com efeitos sutis.',
    },
    {
        id: 'kawaii',
        label: 'Kawaii',
        description: 'Visual fofo com cores pastéis, cards arredondados e mascote na sidebar.',
    },
];

const schemeOptions = [
    {
        id: 'auto',
        label: 'Automático',
        description: 'Segue a preferência do sistema do usuário. O botão de alternância continua visível.',
        icon: Monitor,
    },
    {
        id: 'prefer',
        label: 'Forçar tema',
        description: 'Define claro ou escuro como padrão, mas o usuário ainda pode alternar manualmente.',
        icon: Sun,
    },
    {
        id: 'fixed',
        label: 'Tema fixo',
        description: 'Mantém um único tema em todo o painel, sem botão de alternância.',
        icon: Moon,
    },
];

function normalizeTemplate(value) {
    if (value === 'aurora' || value === 'kawaii') return value;
    return 'default';
}

function mapApiToUi({ mode, locked }) {
    if (mode === 'system' && !locked) {
        schemeUiMode.value = 'auto';
        schemeTheme.value = 'dark';
        return;
    }

    if ((mode === 'light' || mode === 'dark') && !locked) {
        schemeUiMode.value = 'prefer';
        schemeTheme.value = mode;
        return;
    }

    if ((mode === 'light' || mode === 'dark') && locked) {
        schemeUiMode.value = 'fixed';
        schemeTheme.value = mode;
        return;
    }

    schemeUiMode.value = 'auto';
    schemeTheme.value = 'dark';
}

function mapUiToApi() {
    if (schemeUiMode.value === 'auto') {
        return { mode: 'system', locked: false };
    }

    if (schemeUiMode.value === 'prefer') {
        return { mode: schemeTheme.value, locked: false };
    }

    return { mode: schemeTheme.value, locked: true };
}

const showThemeSelect = computed(() => schemeUiMode.value === 'prefer' || schemeUiMode.value === 'fixed');

async function loadTemplate() {
    const res = await window.axios.get('/plataforma/configuracoes/template-dashboard/data');
    selected.value = normalizeTemplate(res.data?.template);
}

async function loadScheme() {
    const res = await window.axios.get('/plataforma/configuracoes/panel-color-scheme/data');
    mapApiToUi(res.data ?? { mode: 'dark', locked: false });
}

async function load() {
    loading.value = true;
    schemeLoading.value = true;
    error.value = '';
    schemeError.value = '';
    try {
        await Promise.all([loadTemplate(), loadScheme()]);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível carregar as configurações.';
    } finally {
        loading.value = false;
        schemeLoading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const res = await window.axios.put('/plataforma/configuracoes/template-dashboard', {
            template: selected.value,
        });
        selected.value = normalizeTemplate(res.data?.template);
        success.value = 'Template salvo. Os infoprodutores verão a alteração ao recarregar o painel.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível salvar o template.';
    } finally {
        saving.value = false;
    }
}

async function saveScheme() {
    schemeSaving.value = true;
    schemeError.value = '';
    schemeSuccess.value = '';
    try {
        const payload = mapUiToApi();
        const res = await window.axios.put('/plataforma/configuracoes/panel-color-scheme', payload);
        mapApiToUi(res.data ?? payload);
        schemeSuccess.value = 'Tema salvo. A alteração vale para todo o painel ao recarregar a página.';
    } catch (e) {
        schemeError.value = e?.response?.data?.message || 'Não foi possível salvar o tema.';
    } finally {
        schemeSaving.value = false;
    }
}

onMounted(() => {
    void load();
});
</script>

<template>
    <div class="space-y-6">
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="mb-6 flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                    <LayoutGrid class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Template da dashboard</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Define o visual do painel do infoprodutor (dashboard, sidebar e cards). As cores da marca continuam em
                        <strong class="font-medium">Personalização</strong>.
                    </p>
                </div>
            </div>

            <p v-if="loading" class="text-sm text-zinc-500">Carregando…</p>
            <p v-if="error" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {{ error }}
            </p>
            <p v-if="success" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">
                {{ success }}
            </p>

            <div v-if="!loading" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="opt in options"
                    :key="opt.id"
                    type="button"
                    class="group relative flex flex-col overflow-hidden rounded-2xl border-2 text-left transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30"
                    :class="selected === opt.id
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 shadow-md'
                        : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-900/40 dark:hover:border-zinc-500'"
                    @click="selected = opt.id"
                >
                    <div class="p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ opt.label }}</span>
                            <span
                                v-if="selected === opt.id"
                                class="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--color-primary)] text-white"
                            >
                                <Check class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                        </div>
                        <p class="text-xs text-zinc-600 dark:text-zinc-400">{{ opt.description }}</p>
                    </div>
                    <div
                        class="border-t border-zinc-200/80 p-3 dark:border-zinc-700/80"
                        :class="{
                            'bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900': opt.id === 'aurora',
                            'bg-gradient-to-br from-violet-50 via-pink-50 to-emerald-50 dark:from-violet-950/40 dark:via-pink-950/30 dark:to-emerald-950/30': opt.id === 'kawaii',
                            'bg-white dark:bg-zinc-800': opt.id === 'default',
                        }"
                    >
                        <div class="flex gap-2">
                            <div
                                class="shrink-0 rounded-lg"
                                :class="{
                                    'h-16 w-10 bg-white/10 backdrop-blur': opt.id === 'aurora',
                                    'h-16 w-10 bg-white/70 dark:bg-white/10': opt.id === 'kawaii',
                                    'h-16 w-8 bg-zinc-200 dark:bg-zinc-700': opt.id === 'default',
                                }"
                            />
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div
                                    class="h-2 rounded"
                                    :class="{
                                        'w-full bg-[var(--color-primary)]/40': opt.id === 'aurora',
                                        'w-full bg-violet-300/60 dark:bg-violet-400/30': opt.id === 'kawaii',
                                        'w-full bg-zinc-200 dark:bg-zinc-600': opt.id === 'default',
                                    }"
                                />
                                <div
                                    class="h-6 rounded-md"
                                    :class="{
                                        'aurora-card-preview w-full': opt.id === 'aurora',
                                        'kawaii-card-preview w-full': opt.id === 'kawaii',
                                        'w-full border border-zinc-200 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700/50': opt.id === 'default',
                                    }"
                                />
                                <div
                                    class="h-6 w-2/3 rounded-md"
                                    :class="{
                                        'kawaii-card-preview': opt.id === 'kawaii',
                                        'aurora-card-preview': opt.id === 'aurora',
                                        'border border-zinc-200 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700/50': opt.id === 'default',
                                    }"
                                />
                            </div>
                        </div>
                    </div>
                    <Sparkles
                        v-if="opt.id === 'aurora'"
                        class="pointer-events-none absolute right-3 top-3 h-4 w-4 text-[var(--color-primary)] opacity-60"
                        aria-hidden="true"
                    />
                    <Heart
                        v-if="opt.id === 'kawaii'"
                        class="pointer-events-none absolute right-3 top-3 h-4 w-4 text-pink-400 opacity-70"
                        aria-hidden="true"
                    />
                </button>
            </div>

            <div v-if="!loading" class="mt-6 flex justify-end">
                <Button type="button" :disabled="saving" @click="save">
                    {{ saving ? 'Salvando…' : 'Salvar template' }}
                </Button>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="mb-6 flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                    <Sun class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Tema claro/escuro</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Controla o tema padrão do painel, login e demais telas. Vale para todos os usuários da plataforma.
                    </p>
                </div>
            </div>

            <p v-if="schemeLoading" class="text-sm text-zinc-500">Carregando…</p>
            <p v-if="schemeError" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {{ schemeError }}
            </p>
            <p v-if="schemeSuccess" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">
                {{ schemeSuccess }}
            </p>

            <div v-if="!schemeLoading" class="space-y-3">
                <label
                    v-for="opt in schemeOptions"
                    :key="opt.id"
                    class="flex cursor-pointer gap-3 rounded-xl border p-4 transition"
                    :class="schemeUiMode === opt.id
                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5'
                        : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-600 dark:hover:border-zinc-500'"
                >
                    <input
                        v-model="schemeUiMode"
                        class="mt-1"
                        type="radio"
                        name="panel_color_scheme_mode"
                        :value="opt.id"
                    >
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                        <component :is="opt.icon" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-zinc-900 dark:text-white">{{ opt.label }}</span>
                        <span class="mt-0.5 block text-xs text-zinc-600 dark:text-zinc-400">{{ opt.description }}</span>
                    </span>
                </label>

                <div v-if="showThemeSelect" class="ml-8 flex flex-wrap items-center gap-3">
                    <label class="text-sm text-zinc-700 dark:text-zinc-300" for="panel-scheme-theme">Tema:</label>
                    <select
                        id="panel-scheme-theme"
                        v-model="schemeTheme"
                        class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                    >
                        <option value="light">Claro</option>
                        <option value="dark">Escuro</option>
                    </select>
                </div>
            </div>

            <div v-if="!schemeLoading" class="mt-6 flex justify-end">
                <Button type="button" :disabled="schemeSaving" @click="saveScheme">
                    {{ schemeSaving ? 'Salvando…' : 'Salvar tema' }}
                </Button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.aurora-card-preview {
    border: 1px solid color-mix(in srgb, var(--color-primary) 35%, transparent);
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--color-primary) 12%, transparent),
        color-mix(in srgb, white 8%, transparent)
    );
    box-shadow: 0 0 20px -8px color-mix(in srgb, var(--color-primary) 50%, transparent);
}

.kawaii-card-preview {
    border: 1px solid color-mix(in srgb, #a78bfa 35%, transparent);
    background: linear-gradient(135deg, #dcfce7, #f3e8ff, #dbeafe);
    box-shadow: 0 4px 16px -6px color-mix(in srgb, #a78bfa 40%, transparent);
    border-radius: 12px;
}
</style>
