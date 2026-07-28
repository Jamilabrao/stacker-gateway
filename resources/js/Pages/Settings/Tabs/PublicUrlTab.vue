<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { Globe, AlertTriangle, Link2, RefreshCw, RotateCcw } from 'lucide-vue-next';

const props = defineProps({
    public_url: { type: String, default: '' },
    resolved_public_url: { type: String, default: '' },
    webhook_public_url: { type: String, default: '' },
    public_url_meta: {
        type: Object,
        default: () => ({}),
    },
    container_restart: {
        type: Object,
        default: () => ({}),
    },
    docker_mode: { type: Boolean, default: false },
});

const page = usePage();
const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));

const loading = ref(false);
const saving = ref(false);
const restarting = ref(false);
const error = ref('');
const success = ref('');
const urlInput = ref(props.public_url || props.resolved_public_url || '');
const meta = ref({ ...props.public_url_meta });
const restartState = ref({ ...props.container_restart });

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
/** @type {import('vue').Ref<'save' | 'restart' | null>} */
const pendingStepUpAction = ref(null);

let pollTimer = null;

watch(
    () => [props.public_url, props.resolved_public_url, props.public_url_meta, props.container_restart],
    () => {
        if (!saving.value) {
            urlInput.value = props.public_url || props.resolved_public_url || urlInput.value;
            meta.value = { ...props.public_url_meta };
        }
        if (props.container_restart && Object.keys(props.container_restart).length) {
            restartState.value = { ...props.container_restart };
        }
    }
);

const previewMemberLink = computed(() => {
    const base = String(urlInput.value || '').trim().replace(/\/+$/, '');
    if (!base) return '';
    const withScheme = base.includes('://') ? base : `https://${base}`;
    try {
        const u = new URL(withScheme);
        return `${u.origin}/m/exemplo`;
    } catch {
        return '';
    }
});

const urlsDiverged = computed(() => Boolean(meta.value?.urls_diverged));
const dockerMode = computed(() => Boolean(props.docker_mode || meta.value?.docker_mode || restartState.value?.docker_mode));
const restartBusy = computed(() => ['pending', 'running'].includes(String(restartState.value?.status || '')));
const canRestart = computed(() => dockerMode.value && (restartState.value?.can_request !== false) && !restartBusy.value);

const stepUpTitle = computed(() =>
    pendingStepUpAction.value === 'restart' ? 'Confirmar reinício dos containers' : 'Confirmar alteração de URL'
);
const stepUpDescription = computed(() =>
    pendingStepUpAction.value === 'restart'
        ? 'Informe o código 2FA para reiniciar app, workers e agente Stacker.'
        : 'Informe o código 2FA para alterar a URL pública da instalação (.env, webhooks e área de membros).'
);
const stepUpConfirmLabel = computed(() =>
    pendingStepUpAction.value === 'restart' ? 'Reiniciar' : 'Salvar URL'
);

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function startPolling() {
    stopPolling();
    pollTimer = setInterval(async () => {
        try {
            const res = await window.axios.get('/plataforma/configuracoes/url-publica/reiniciar-containers');
            restartState.value = res.data?.container_restart || restartState.value;
            const st = String(restartState.value?.status || '');
            if (st === 'completed') {
                success.value = restartState.value?.message || 'Containers reiniciados.';
                restarting.value = false;
                stopPolling();
            } else if (st === 'failed') {
                error.value = restartState.value?.message || 'Falha ao reiniciar containers.';
                restarting.value = false;
                stopPolling();
            }
        } catch (_) {
            // Durante recreate do app a API pode cair brevemente.
        }
    }, 2500);
}

onMounted(() => {
    if (restartBusy.value) {
        restarting.value = true;
        startPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const res = await window.axios.get('/plataforma/configuracoes/url-publica/data');
        meta.value = res.data || {};
        urlInput.value = res.data?.app_url || res.data?.resolved_public_url || urlInput.value;
        if (res.data?.container_restart) {
            restartState.value = res.data.container_restart;
        }
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível carregar a URL pública.';
    } finally {
        loading.value = false;
    }
}

function requestSave() {
    error.value = '';
    success.value = '';
    if (!String(urlInput.value || '').trim()) {
        error.value = 'Informe a URL pública da instalação.';
        return;
    }
    if (platformTotpEnabled.value) {
        pendingStepUpAction.value = 'save';
        stepUpOpen.value = true;
        return;
    }
    save();
}

function requestRestart() {
    error.value = '';
    success.value = '';
    if (!dockerMode.value) {
        error.value = 'Reinício de containers só está disponível em instalação Docker.';
        return;
    }
    if (platformTotpEnabled.value) {
        pendingStepUpAction.value = 'restart';
        stepUpOpen.value = true;
        return;
    }
    restartContainers();
}

async function save(totpCode) {
    saving.value = true;
    stepUpLoading.value = true;
    error.value = '';
    success.value = '';
    try {
        const payload = { url: String(urlInput.value || '').trim() };
        if (totpCode) {
            payload.totp_code = totpCode;
        }
        const res = await window.axios.put('/plataforma/configuracoes/url-publica', payload);
        success.value = res.data?.message || 'URL pública atualizada.';
        meta.value = {
            ...meta.value,
            ...res.data,
            urls_diverged: false,
        };
        urlInput.value = res.data?.url || urlInput.value;
        stepUpOpen.value = false;
        pendingStepUpAction.value = null;
    } catch (e) {
        const msg =
            e?.response?.data?.message ||
            e?.response?.data?.errors?.totp_code?.[0] ||
            e?.response?.data?.errors?.url?.[0] ||
            'Não foi possível salvar a URL pública.';
        error.value = msg;
        if (e?.response?.status === 422 && platformTotpEnabled.value && /2FA|totp/i.test(msg)) {
            pendingStepUpAction.value = 'save';
            stepUpOpen.value = true;
        }
    } finally {
        saving.value = false;
        stepUpLoading.value = false;
    }
}

async function restartContainers(totpCode) {
    restarting.value = true;
    stepUpLoading.value = true;
    error.value = '';
    success.value = '';
    try {
        const payload = { reason: 'public_url_settings' };
        if (totpCode) {
            payload.totp_code = totpCode;
        }
        const res = await window.axios.post('/plataforma/configuracoes/url-publica/reiniciar-containers', payload);
        restartState.value = res.data?.container_restart || restartState.value;
        success.value = res.data?.message || 'Reinício solicitado.';
        stepUpOpen.value = false;
        pendingStepUpAction.value = null;
        startPolling();
    } catch (e) {
        restarting.value = false;
        const msg =
            e?.response?.data?.message ||
            e?.response?.data?.errors?.totp_code?.[0] ||
            'Não foi possível solicitar o reinício.';
        error.value = msg;
        if (e?.response?.status === 422 && platformTotpEnabled.value && /2FA|totp/i.test(msg)) {
            pendingStepUpAction.value = 'restart';
            stepUpOpen.value = true;
        }
    } finally {
        stepUpLoading.value = false;
    }
}

function onStepUpConfirm({ totp_code: totpCode }) {
    if (pendingStepUpAction.value === 'restart') {
        restartContainers(totpCode);
        return;
    }
    save(totpCode);
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
    pendingStepUpAction.value = null;
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
            >
                <Globe class="h-5 w-5" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">URL pública da instalação</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Define o domínio usado em área de membros, checkout, webhooks de pagamento, e-mails e agente Stacker.
                    Use a URL completa do painel (ex.: <code class="text-xs">https://app.seudominio.com</code>).
                </p>
            </div>
        </div>

        <div
            v-if="urlsDiverged"
            class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0" />
            <div>
                <p class="font-medium">URLs desalinhadas</p>
                <p class="mt-1 text-xs opacity-90">
                    <code>APP_URL</code> e <code>GETFY_WEBHOOK_PUBLIC_URL</code> estão diferentes. Isso faz a área de membros
                    e os postbacks usarem hosts distintos. Salve a URL correta abaixo para alinhar tudo.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                        URL pública
                    </label>
                    <input
                        v-model="urlInput"
                        type="url"
                        autocomplete="off"
                        placeholder="https://app.seudominio.com"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30 dark:border-zinc-600 dark:bg-zinc-950 dark:text-zinc-100"
                        :disabled="saving || loading || restarting"
                        @keydown.enter.prevent="requestSave"
                    />
                </div>

                <div
                    v-if="previewMemberLink"
                    class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-950/50"
                >
                    <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        <Link2 class="h-3.5 w-3.5" />
                        Preview área de membros
                    </p>
                    <code class="mt-1 block break-all text-sm text-zinc-800 dark:text-zinc-200">{{ previewMemberLink }}</code>
                </div>

                <div class="grid gap-3 text-xs text-zinc-500 dark:text-zinc-400 sm:grid-cols-2">
                    <div>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">APP_URL atual</span>
                        <p class="mt-0.5 break-all font-mono">{{ meta.app_url || public_url || '—' }}</p>
                    </div>
                    <div>
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">Webhook / resolvida</span>
                        <p class="mt-0.5 break-all font-mono">
                            {{ meta.webhook_public_url || webhook_public_url || resolved_public_url || '—' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="dockerMode"
                    class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-3 text-xs text-sky-900 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-100"
                >
                    <p class="font-medium">Containers Docker</p>
                    <p class="mt-1 opacity-90">
                        Após salvar a URL, use <strong>Reiniciar containers</strong> para o agente e workers
                        recarregarem <code>APP_URL</code> / <code>GETFY_*</code>. O painel pode ficar indisponível por alguns segundos.
                    </p>
                    <p v-if="restartState?.status && restartState.status !== 'idle'" class="mt-2 font-mono">
                        Status: {{ restartState.status }}
                        <span v-if="restartState.message"> — {{ restartState.message }}</span>
                    </p>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
                <p v-if="success" class="text-sm text-emerald-600 dark:text-emerald-400">{{ success }}</p>

                <div class="flex flex-wrap gap-2">
                    <Button type="button" :disabled="saving || loading || restarting" @click="requestSave">
                        {{ saving ? 'Salvando…' : 'Salvar URL' }}
                    </Button>
                    <Button
                        v-if="dockerMode"
                        type="button"
                        variant="secondary"
                        :disabled="saving || loading || restarting || !canRestart"
                        @click="requestRestart"
                    >
                        <RotateCcw class="mr-1.5 h-4 w-4" :class="{ 'animate-spin': restarting || restartBusy }" />
                        {{ restarting || restartBusy ? 'Reiniciando…' : 'Reiniciar containers' }}
                    </Button>
                    <Button type="button" variant="outline" :disabled="saving || loading || restarting" @click="load">
                        <RefreshCw class="mr-1.5 h-4 w-4" />
                        Recarregar
                    </Button>
                </div>
            </div>
        </div>

        <PlatformStepUpModal
            :open="stepUpOpen"
            :title="stepUpTitle"
            :description="stepUpDescription"
            :confirm-label="stepUpConfirmLabel"
            :loading="stepUpLoading"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </section>
</template>
