<script setup>
import { ref, onMounted } from 'vue';
import Button from '@/components/ui/Button.vue';
import { PlayCircle, AlertTriangle, UserCog, Store } from 'lucide-vue-next';

const loading = ref(true);
const saving = ref(false);
const provisioning = ref(false);
const error = ref('');
const success = ref('');

const state = ref({
    enabled: false,
    can_configure: true,
    admin_user_id: null,
    seller_user_id: null,
    admin_label: null,
    seller_label: null,
    env_admin_email: null,
    env_seller_email: null,
});

const adminCandidates = ref([]);
const sellerCandidates = ref([]);
const search = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const res = await window.axios.get('/plataforma/configuracoes/demo/data', {
            params: search.value ? { q: search.value } : {},
        });
        state.value = {
            enabled: !!res.data?.enabled,
            can_configure: res.data?.can_configure !== false,
            admin_user_id: res.data?.admin_user_id ?? null,
            seller_user_id: res.data?.seller_user_id ?? null,
            admin_label: res.data?.admin_label ?? null,
            seller_label: res.data?.seller_label ?? null,
            env_admin_email: res.data?.env_admin_email ?? null,
            env_seller_email: res.data?.env_seller_email ?? null,
        };
        adminCandidates.value = res.data?.admin_candidates ?? [];
        sellerCandidates.value = res.data?.seller_candidates ?? [];
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível carregar a configuração demo.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    if (!state.value.can_configure) return;
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const res = await window.axios.put('/plataforma/configuracoes/demo', {
            admin_user_id: state.value.admin_user_id,
            seller_user_id: state.value.seller_user_id,
        });
        success.value = res.data?.message || 'Configuração salva.';
        await load();
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível salvar.';
    } finally {
        saving.value = false;
    }
}

async function provision() {
    if (!state.value.can_configure) return;
    provisioning.value = true;
    error.value = '';
    success.value = '';
    try {
        const res = await window.axios.post('/plataforma/configuracoes/demo/provision');
        success.value = res.data?.message || 'Contas demo provisionadas.';
        await load();
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível provisionar contas.';
    } finally {
        provisioning.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300"
            >
                <PlayCircle class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Modo demonstração</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Ambiente read-only para visitantes explorarem a plataforma com login rápido e dados ilustrativos.
                </p>
            </div>
        </div>

        <div
            class="rounded-xl border p-4"
            :class="state.enabled
                ? 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40'
                : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50'"
        >
            <div class="flex items-start gap-3">
                <AlertTriangle
                    class="mt-0.5 h-5 w-5 shrink-0"
                    :class="state.enabled ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500'"
                />
                <div class="space-y-2 text-sm">
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">
                        Status:
                        <span :class="state.enabled ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-700 dark:text-emerald-300'">
                            {{ state.enabled ? 'Ativo via .env' : 'Inativo' }}
                        </span>
                    </p>
                    <p class="text-zinc-600 dark:text-zinc-400">
                        O interruptor mestre é <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-800">GETFY_DEMO_MODE</code> no
                        <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-800">.env</code>.
                        Com demo ativo, nenhuma alteração é permitida pelo painel (somente navegação).
                    </p>
                    <ul class="list-inside list-disc space-y-1 text-zinc-600 dark:text-zinc-400">
                        <li>Ativar: <code class="text-xs">GETFY_DEMO_MODE=true</code> + <code class="text-xs">php artisan config:clear</code></li>
                        <li>Desativar: <code class="text-xs">GETFY_DEMO_MODE=false</code> + <code class="text-xs">php artisan config:clear</code></li>
                    </ul>
                </div>
            </div>
        </div>

        <p v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
            {{ error }}
        </p>
        <p v-if="success" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ success }}
        </p>

        <div v-if="loading" class="text-sm text-zinc-500">Carregando…</div>

        <template v-else>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        <UserCog class="h-4 w-4 text-zinc-500" />
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Conta admin demo</h3>
                    </div>
                    <p v-if="state.admin_label" class="mb-3 text-xs text-zinc-500">Atual: {{ state.admin_label }}</p>
                    <select
                        v-model="state.admin_user_id"
                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        :disabled="!state.can_configure"
                    >
                        <option :value="null">— Selecionar —</option>
                        <option v-for="u in adminCandidates" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.email }})
                        </option>
                    </select>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        <Store class="h-4 w-4 text-zinc-500" />
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Conta infoprodutor demo</h3>
                    </div>
                    <p v-if="state.seller_label" class="mb-3 text-xs text-zinc-500">Atual: {{ state.seller_label }}</p>
                    <select
                        v-model="state.seller_user_id"
                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        :disabled="!state.can_configure"
                    >
                        <option :value="null">— Selecionar —</option>
                        <option v-for="u in sellerCandidates" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.email }})
                        </option>
                    </select>
                </div>
            </div>

            <p v-if="state.env_admin_email || state.env_seller_email" class="text-xs text-zinc-500">
                Fallback .env:
                <span v-if="state.env_admin_email"> admin {{ state.env_admin_email }}</span>
                <span v-if="state.env_seller_email"> · vendedor {{ state.env_seller_email }}</span>
            </p>

            <div class="flex flex-wrap gap-3">
                <Button type="button" :disabled="!state.can_configure || saving" @click="save">
                    {{ saving ? 'Salvando…' : 'Salvar contas demo' }}
                </Button>
                <Button type="button" variant="outline" :disabled="!state.can_configure || provisioning" @click="provision">
                    {{ provisioning ? 'Criando…' : 'Criar contas demo automaticamente' }}
                </Button>
            </div>

            <p class="text-xs text-zinc-500">
                Fluxo recomendado: 1) configure ou crie as contas aqui · 2) ligue <code>GETFY_DEMO_MODE=true</code> no .env · 3) visitantes usam os botões na tela de login.
            </p>
        </template>
    </section>
</template>
