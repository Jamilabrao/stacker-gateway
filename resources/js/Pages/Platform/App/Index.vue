<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Upload, Trash2, Send, RefreshCw, KeyRound } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    app: { type: Object, required: true },
    push_subscriptions_count: { type: Number, default: 0 },
});

const activeTab = ref('pwa');
const loading = ref(true);
const pushLoading = ref(true);
const saving = ref(false);
const savingPush = ref(false);
const error = ref('');
const uploading = ref(false);
const uploadField = ref(null);
const uploadingSa = ref(false);
const generatingVapid = ref(false);
const sendingPush = ref(false);
const testingPush = ref(false);
const pushResult = ref(null);
const subscribers = ref([]);
const subscribersMeta = ref({ current_page: 1, last_page: 1, total: 0 });
const subscriberSearch = ref('');
const subscriberPage = ref(1);

const form = reactive({
    app_name: '',
    pwa_theme_color: '',
    pwa_icon_192: '',
    pwa_icon_512: '',
});

const pushSettings = reactive({
    push_provider: 'vapid',
    pwa_vapid_public: '',
    pwa_vapid_private: '',
    firebase_project_id: '',
    firebase_api_key: '',
    firebase_messaging_sender_id: '',
    firebase_app_id: '',
    firebase_web_vapid_key: '',
});

const pushStats = reactive({
    subscribers_count: 0,
    subscribers_by_provider: { vapid: 0, fcm: 0 },
    vapid_valid: false,
    fcm_valid: false,
    push_enabled: false,
    pwa_vapid_private_configured: false,
    firebase_service_account_configured: false,
});

const pushForm = reactive({
    title: '',
    body: '',
    url: '',
});

const fieldLabels = {
    pwa_icon_192: 'Ícone PWA 192x192',
    pwa_icon_512: 'Ícone PWA 512x512',
};

async function loadPwa() {
    const res = await window.axios.get('/plataforma/app/data');
    const app = res.data?.app ?? props.app ?? {};
    form.app_name = app.app_name || '';
    form.pwa_theme_color = app.pwa_theme_color || '';
    form.pwa_icon_192 = app.pwa_icon_192 || '';
    form.pwa_icon_512 = app.pwa_icon_512 || '';
}

async function loadPush() {
    pushLoading.value = true;
    try {
        const res = await window.axios.get('/plataforma/app/push/data');
        const push = res.data?.push ?? {};
        pushSettings.push_provider = push.push_provider || 'vapid';
        pushSettings.pwa_vapid_public = push.pwa_vapid_public || '';
        pushSettings.pwa_vapid_private = '';
        pushSettings.firebase_project_id = push.firebase_project_id || '';
        pushSettings.firebase_api_key = push.firebase_api_key || '';
        pushSettings.firebase_messaging_sender_id = push.firebase_messaging_sender_id || '';
        pushSettings.firebase_app_id = push.firebase_app_id || '';
        pushSettings.firebase_web_vapid_key = push.firebase_web_vapid_key || '';
        pushStats.subscribers_count = res.data?.subscribers_count ?? 0;
        pushStats.subscribers_by_provider = res.data?.subscribers_by_provider ?? { vapid: 0, fcm: 0 };
        pushStats.vapid_valid = !!push.vapid_valid;
        pushStats.fcm_valid = !!push.fcm_valid;
        pushStats.push_enabled = !!push.push_enabled;
        pushStats.pwa_vapid_private_configured = !!push.pwa_vapid_private_configured;
        pushStats.firebase_service_account_configured = !!push.firebase_service_account_configured;
        await loadSubscribers();
    } finally {
        pushLoading.value = false;
    }
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        await Promise.all([loadPwa(), loadPush()]);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível carregar configurações.';
    } finally {
        loading.value = false;
    }
}

async function loadSubscribers(page = 1) {
    subscriberPage.value = page;
    const res = await window.axios.get('/plataforma/app/push/subscribers', {
        params: { page, search: subscriberSearch.value || undefined },
    });
    subscribers.value = res.data?.data ?? [];
    subscribersMeta.value = res.data?.meta ?? { current_page: 1, last_page: 1, total: 0 };
}

async function savePwa() {
    saving.value = true;
    error.value = '';
    try {
        await window.axios.put('/plataforma/app', { ...form });
        await router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao salvar PWA.';
    } finally {
        saving.value = false;
    }
}

async function savePushSettings() {
    savingPush.value = true;
    error.value = '';
    try {
        const payload = { ...pushSettings };
        if (!payload.pwa_vapid_private) delete payload.pwa_vapid_private;
        const res = await window.axios.put('/plataforma/app/push', payload);
        if (res.data?.push) {
            pushStats.push_enabled = !!res.data.push.push_enabled;
            pushStats.vapid_valid = !!res.data.push.vapid_valid;
            pushStats.fcm_valid = !!res.data.push.fcm_valid;
            pushStats.pwa_vapid_private_configured = !!res.data.push.pwa_vapid_private_configured;
            pushStats.firebase_service_account_configured = !!res.data.push.firebase_service_account_configured;
        }
        await router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao salvar push.';
    } finally {
        savingPush.value = false;
    }
}

async function generateVapid() {
    if (!confirm('Gerar novo par VAPID? Dispositivos inscritos deixarão de receber push até reativarem notificações.')) return;
    generatingVapid.value = true;
    error.value = '';
    try {
        const res = await window.axios.post('/plataforma/app/push/generate-vapid');
        pushSettings.pwa_vapid_public = res.data?.public_key || pushSettings.pwa_vapid_public;
        pushStats.pwa_vapid_private_configured = true;
        pushStats.vapid_valid = true;
        if (res.data?.message) error.value = res.data.message;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao gerar VAPID.';
    } finally {
        generatingVapid.value = false;
    }
}

async function onServiceAccountChange(event) {
    const file = event.target?.files?.[0];
    event.target.value = '';
    if (!file) return;
    uploadingSa.value = true;
    error.value = '';
    const fd = new FormData();
    fd.append('file', file);
    try {
        const res = await window.axios.post('/plataforma/app/push/upload-service-account', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (res.data?.push) {
            pushStats.firebase_service_account_configured = !!res.data.push.firebase_service_account_configured;
            pushStats.fcm_valid = !!res.data.push.fcm_valid;
        }
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro no upload da service account.';
    } finally {
        uploadingSa.value = false;
    }
}

async function clearProviderSubscriptions(provider) {
    const label = provider === 'fcm' ? 'Firebase' : 'VAPID';
    if (!confirm(`Remover todas as inscrições ${label}?`)) return;
    try {
        await window.axios.post('/plataforma/app/push/clear-provider-subscriptions', { provider });
        await loadPush();
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao limpar inscrições.';
    }
}

async function deleteSubscriber(id) {
    if (!confirm('Remover esta inscrição?')) return;
    try {
        await window.axios.delete(`/plataforma/app/push/subscribers/${id}`);
        await loadSubscribers(subscriberPage.value);
        pushStats.subscribers_count = Math.max(0, pushStats.subscribers_count - 1);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao remover.';
    }
}

async function testPush() {
    testingPush.value = true;
    error.value = '';
    try {
        const res = await window.axios.post('/plataforma/app/push/test');
        if (!res.data?.ok) error.value = res.data?.message || 'Teste não entregue.';
        else pushResult.value = res.data?.result ?? null;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro no teste.';
    } finally {
        testingPush.value = false;
    }
}

async function onFileChange(event, field) {
    const file = event.target?.files?.[0];
    event.target.value = '';
    if (!file) return;
    uploading.value = true;
    uploadField.value = field;
    error.value = '';
    const fd = new FormData();
    fd.append('field', field);
    fd.append('file', file);
    try {
        const res = await window.axios.post('/plataforma/app/upload', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (res.data?.field && res.data?.url) form[res.data.field] = res.data.url;
        await router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao enviar ícone.';
    } finally {
        uploading.value = false;
        uploadField.value = null;
    }
}

async function clearField(field) {
    try {
        await window.axios.post('/plataforma/app/clear-field', { field });
        form[field] = '';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao remover.';
    }
}

async function sendPush() {
    sendingPush.value = true;
    error.value = '';
    pushResult.value = null;
    try {
        const res = await window.axios.post('/plataforma/app/push/send', { ...pushForm });
        if (res.data?.ok === false) {
            error.value = res.data?.message || 'Não foi possível enviar.';
            pushResult.value = res.data?.result ?? null;
            return;
        }
        pushResult.value = res.data?.result ?? null;
        if (res.data?.message) error.value = res.data.message;
        pushForm.title = '';
        pushForm.body = '';
        pushForm.url = '';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao enviar push.';
        pushResult.value = e?.response?.data?.result ?? null;
    } finally {
        sendingPush.value = false;
    }
}

let searchTimer;
watch(subscriberSearch, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadSubscribers(1), 400);
});

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">App</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                PWA do painel e notificações push (VAPID ou Firebase).
            </p>
        </div>

        <p v-if="error" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100">
            {{ error }}
        </p>

        <div class="flex gap-2 border-b border-zinc-200 dark:border-zinc-700">
            <button
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium transition"
                :class="activeTab === 'pwa' ? 'border-[var(--color-primary)] text-[var(--color-primary)]' : 'border-transparent text-zinc-500'"
                @click="activeTab = 'pwa'"
            >
                PWA
            </button>
            <button
                type="button"
                class="border-b-2 px-4 py-2 text-sm font-medium transition"
                :class="activeTab === 'push' ? 'border-[var(--color-primary)] text-[var(--color-primary)]' : 'border-transparent text-zinc-500'"
                @click="activeTab = 'push'"
            >
                Notificações push
            </button>
        </div>

        <section
            v-show="activeTab === 'pwa'"
            class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50"
        >
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">PWA</h2>
            <div v-if="loading" class="mt-5 text-sm text-zinc-500">Carregando...</div>
            <div v-else class="mt-5 space-y-6">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do App</label>
                        <input v-model="form.app_name" type="text" class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cor do tema</label>
                        <div class="mt-1.5 flex gap-2">
                            <input v-model="form.pwa_theme_color" type="text" class="block min-w-0 flex-1 rounded-xl border border-zinc-300 px-4 py-2.5 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            <input v-model="form.pwa_theme_color" type="color" class="h-11 w-14 rounded-lg border dark:border-zinc-600" />
                        </div>
                    </div>
                </div>
                <div class="grid gap-6 md:grid-cols-2">
                    <div v-for="field in ['pwa_icon_192', 'pwa_icon_512']" :key="field" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-600">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium">{{ fieldLabels[field] }}</span>
                            <button v-if="form[field]" type="button" @click="clearField(field)"><Trash2 class="h-4 w-4 text-zinc-500" /></button>
                        </div>
                        <img v-if="form[field]" :src="form[field]" class="mt-3 max-h-32 object-contain" alt="" />
                        <label class="mt-3 flex cursor-pointer gap-2 text-sm text-[var(--color-primary)]">
                            <Upload class="h-4 w-4" />
                            <span>Enviar</span>
                            <input type="file" accept="image/*" class="hidden" @change="(e) => onFileChange(e, field)" />
                        </label>
                    </div>
                </div>
                <Button type="button" :disabled="saving" @click="savePwa">{{ saving ? 'Salvando...' : 'Salvar PWA' }}</Button>
            </div>
        </section>

        <template v-if="activeTab === 'push'">
            <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Provedor</h2>
                <p v-if="pushLoading" class="mt-4 text-sm text-zinc-500">Carregando...</p>
                <div v-else class="mt-4 space-y-4">
                    <div class="flex flex-wrap gap-4 text-sm">
                        <span>Inscritos: <strong>{{ pushStats.subscribers_count }}</strong></span>
                        <span>VAPID: {{ pushStats.subscribers_by_provider.vapid }}</span>
                        <span>FCM: {{ pushStats.subscribers_by_provider.fcm }}</span>
                        <span :class="pushStats.push_enabled ? 'text-green-600' : 'text-red-600'">
                            {{ pushStats.push_enabled ? 'Push ativo' : 'Push inativo' }}
                        </span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Provedor</label>
                        <select v-model="pushSettings.push_provider" class="mt-1.5 rounded-xl border border-zinc-300 px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-900">
                            <option value="vapid">VAPID (Web Push nativo)</option>
                            <option value="fcm">Firebase Cloud Messaging</option>
                        </select>
                    </div>

                    <div v-if="pushSettings.push_provider === 'vapid'" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-600">
                        <p class="text-xs text-zinc-500">Configure aqui em vez do .env. Trocar chaves exige que usuários reativem notificações.</p>
                        <div>
                            <label class="text-sm font-medium">Chave pública VAPID</label>
                            <input v-model="pushSettings.pwa_vapid_public" type="text" readonly class="mt-1 w-full rounded-xl border px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900" />
                        </div>
                        <div>
                            <label class="text-sm font-medium">Chave privada (opcional, sobrescrever)</label>
                            <input v-model="pushSettings.pwa_vapid_private" type="password" placeholder="Deixe vazio para manter a atual" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                            <p v-if="pushStats.pwa_vapid_private_configured" class="mt-1 text-xs text-green-600">Privada configurada.</p>
                        </div>
                        <Button type="button" variant="outline" class="inline-flex gap-2" :disabled="generatingVapid" @click="generateVapid">
                            <KeyRound class="h-4 w-4" />
                            {{ generatingVapid ? 'Gerando...' : 'Gerar par VAPID' }}
                        </Button>
                    </div>

                    <div v-else class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-600">
                        <p class="text-xs text-zinc-500">
                            Crie um app Web no Firebase Console. Envie o JSON da service account (Conta de serviço → Gerar chave).
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input v-model="pushSettings.firebase_project_id" placeholder="Project ID" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                            <input v-model="pushSettings.firebase_api_key" placeholder="API Key" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                            <input v-model="pushSettings.firebase_messaging_sender_id" placeholder="Sender ID" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                            <input v-model="pushSettings.firebase_app_id" placeholder="App ID" class="rounded-xl border px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                        </div>
                        <input v-model="pushSettings.firebase_web_vapid_key" placeholder="Web Push certificate (VAPID key do Firebase)" class="w-full rounded-xl border px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900" />
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-[var(--color-primary)]">
                            <Upload class="h-4 w-4" />
                            {{ pushStats.firebase_service_account_configured ? 'Substituir service account JSON' : 'Enviar service account JSON' }}
                            <input type="file" accept=".json" class="hidden" @change="onServiceAccountChange" />
                        </label>
                        <p v-if="uploadingSa" class="text-xs text-zinc-500">Enviando...</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button type="button" :disabled="savingPush" @click="savePushSettings">{{ savingPush ? 'Salvando...' : 'Salvar provedor' }}</Button>
                        <Button type="button" variant="outline" :disabled="testingPush" @click="testPush">{{ testingPush ? 'Testando...' : 'Testar neste dispositivo' }}</Button>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold">Inscritos</h2>
                    <div class="flex gap-2">
                        <input v-model="subscriberSearch" type="search" placeholder="Buscar e-mail..." class="rounded-lg border px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                        <button type="button" class="rounded-lg p-2 hover:bg-zinc-100 dark:hover:bg-zinc-700" @click="loadSubscribers(subscriberPage)">
                            <RefreshCw class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-zinc-500">
                            <tr>
                                <th class="py-2 pr-4">Usuário</th>
                                <th class="py-2 pr-4">Provedor</th>
                                <th class="py-2 pr-4">Atualizado</th>
                                <th class="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in subscribers" :key="row.id" class="border-t border-zinc-100 dark:border-zinc-700">
                                <td class="py-2 pr-4">
                                    <div>{{ row.user_name || '—' }}</div>
                                    <div class="text-xs text-zinc-500">{{ row.user_email }}</div>
                                </td>
                                <td class="py-2 pr-4 uppercase">{{ row.provider }}</td>
                                <td class="py-2 pr-4 text-xs">{{ row.updated_at ? new Date(row.updated_at).toLocaleString() : '—' }}</td>
                                <td class="py-2 text-right">
                                    <button type="button" class="text-red-600 text-xs" @click="deleteSubscriber(row.id)">Remover</button>
                                </td>
                            </tr>
                            <tr v-if="!subscribers.length">
                                <td colspan="4" class="py-6 text-center text-zinc-500">Nenhum inscrito.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="subscribersMeta.last_page > 1" class="mt-3 flex justify-center gap-2">
                    <Button type="button" variant="outline" :disabled="subscriberPage <= 1" @click="loadSubscribers(subscriberPage - 1)">Anterior</Button>
                    <span class="py-2 text-sm">{{ subscriberPage }} / {{ subscribersMeta.last_page }}</span>
                    <Button type="button" variant="outline" :disabled="subscriberPage >= subscribersMeta.last_page" @click="loadSubscribers(subscriberPage + 1)">Próxima</Button>
                </div>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                <h2 class="text-base font-semibold">Disparo manual</h2>
                <div class="mt-4 space-y-4">
                    <input v-model="pushForm.title" placeholder="Título" class="w-full rounded-xl border px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-900" />
                    <textarea v-model="pushForm.body" rows="3" placeholder="Mensagem" class="w-full rounded-xl border px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-900" />
                    <input v-model="pushForm.url" placeholder="URL ao clicar (opcional)" class="w-full rounded-xl border px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-900" />
                    <Button type="button" class="inline-flex gap-2" :disabled="sendingPush" @click="sendPush">
                        <Send class="h-4 w-4" />
                        {{ sendingPush ? 'Enviando...' : 'Enviar para todos' }}
                    </Button>
                    <p v-if="pushResult" class="text-sm text-zinc-600">
                        Enviados: {{ pushResult.sent }} / {{ pushResult.total }} — Falhas: {{ pushResult.failed }} — Expirados: {{ pushResult.expired }}
                    </p>
                </div>
            </section>
        </template>
    </div>
</template>
