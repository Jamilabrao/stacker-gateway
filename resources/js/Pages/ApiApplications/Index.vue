<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ApiKeySidebar from '@/components/api-applications/ApiKeySidebar.vue';
import ApiWebhookSidebar from '@/components/api-applications/ApiWebhookSidebar.vue';
import ApiWebhookCard from '@/components/api-applications/ApiWebhookCard.vue';
import {
    Shield, Webhook, FileText, Plus, Copy, Check, Eye, EyeOff, Settings2, RefreshCw, Trash2, KeyRound, X,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    application: { type: Object, required: true },
    keys: { type: Array, default: () => [] },
    webhook: { type: Object, default: () => ({}) },
    webhook_event_catalog: { type: Object, default: () => ({}) },
    available_scopes: { type: Array, default: () => [] },
    scope_catalog: { type: Array, default: () => [] },
    recent_deliveries: { type: Array, default: () => [] },
    api_key_reveal: { type: Object, default: null },
    webhook_secret_reveal: { type: String, default: null },
    webhook_secret_mask: { type: String, default: '' },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);

const tabs = [
    { id: 'chaves', label: 'Chaves', icon: Shield },
    { id: 'webhooks', label: 'Webhooks', icon: Webhook },
];

function tabFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const t = params.get('tab');
    return t === 'webhooks' ? 'webhooks' : 'chaves';
}

const activeTab = ref(tabFromUrl());

watch(() => page.url, () => {
    activeTab.value = tabFromUrl();
});

function setTab(id) {
    activeTab.value = id;
    router.visit(`/aplicacoes-api?tab=${id}`, { preserveState: true, preserveScroll: true, replace: true });
}

const keySidebarOpen = ref(false);
const editingKey = ref(null);
const webhookSidebarOpen = ref(false);

const showKeyModal = ref(!!props.api_key_reveal);
const revealedPublicKey = ref(props.api_key_reveal?.public_key ?? '');
const revealedSecretKey = ref(props.api_key_reveal?.secret_key ?? '');
const copyKeyFeedback = ref(false);

const showWebhookSecretModal = ref(!!props.webhook_secret_reveal);
const revealedWebhookSecret = ref(props.webhook_secret_reveal ?? '');

const revealedSecrets = ref({});
const revealLoading = ref({});
const revealErrors = ref({});
const copyDone = ref({});

watch(() => props.api_key_reveal, (val) => {
    if (val) {
        revealedPublicKey.value = val.public_key ?? '';
        revealedSecretKey.value = val.secret_key ?? '';
        showKeyModal.value = true;
        copyKeyFeedback.value = false;
    }
});

watch(() => props.webhook_secret_reveal, (val) => {
    if (val) {
        revealedWebhookSecret.value = val;
        showWebhookSecretModal.value = true;
    }
});

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function openCreateKey() {
    editingKey.value = null;
    keySidebarOpen.value = true;
}

function openEditKey(key) {
    editingKey.value = key;
    keySidebarOpen.value = true;
}

function maskSecret() {
    return 'gsk_******************';
}

async function copyToClipboard(text) {
    if (!text) return false;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
}

async function copyText(key, text) {
    const value = text ?? '';
    if (!value) return;
    const ok = await copyToClipboard(value);
    copyDone.value = { ...copyDone.value, [key]: ok };
    if (ok) {
        setTimeout(() => {
            copyDone.value = { ...copyDone.value, [key]: false };
        }, 2000);
    }
}

async function revealSecret(keyRow) {
    const rowKey = String(keyRow.id);
    revealErrors.value = { ...revealErrors.value, [rowKey]: '' };
    revealLoading.value = { ...revealLoading.value, [rowKey]: true };
    try {
        const url = keyRow.type === 'legacy'
            ? `/aplicacoes-api/${props.application.id}/reveal-secret`
            : `/aplicacoes-api/${props.application.id}/keys/${keyRow.id}/reveal-secret`;
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
            body: '{}',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            revealErrors.value = { ...revealErrors.value, [rowKey]: data.message || 'Não foi possível revelar.' };
            return;
        }
        revealedSecrets.value = { ...revealedSecrets.value, [rowKey]: data.secret_key ?? '' };
    } finally {
        revealLoading.value = { ...revealLoading.value, [rowKey]: false };
    }
}

function hideRevealed(rowKey) {
    const next = { ...revealedSecrets.value };
    delete next[rowKey];
    revealedSecrets.value = next;
}

function copyRevealedSecret(keyRow) {
    const rowKey = String(keyRow.id);
    copyText(`sec-${rowKey}`, revealedSecrets.value[rowKey]);
}

function regenerateKey(keyRow) {
    if (!confirm('Gerar novas credenciais? As atuais deixam de funcionar imediatamente.')) return;
    if (keyRow.type === 'legacy') {
        router.post(`/aplicacoes-api/${props.application.id}/regenerate-key`, {}, { preserveScroll: true });
        return;
    }
    router.post(`/aplicacoes-api/${props.application.id}/keys/${keyRow.id}/regenerate`, {}, { preserveScroll: true });
}

function toggleKeyActive(keyRow) {
    if (keyRow.type === 'legacy') {
        router.put(`/aplicacoes-api/${props.application.id}`, {
            is_active: !keyRow.is_active,
        }, { preserveScroll: true });
        return;
    }
    router.put(`/aplicacoes-api/${props.application.id}/keys/${keyRow.id}`, {
        name: keyRow.name,
        scopes: keyRow.scopes,
        is_active: !keyRow.is_active,
    }, { preserveScroll: true });
}

function deleteKey(keyRow) {
    if (!confirm('Remover esta chave? Integrações que a usam deixarão de funcionar.')) return;
    router.delete(`/aplicacoes-api/${props.application.id}/keys/${keyRow.id}`, { preserveScroll: true });
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

async function copyKeyModal(text) {
    if (!text) return;
    const ok = await copyToClipboard(text);
    copyKeyFeedback.value = ok;
    if (ok) {
        setTimeout(() => { copyKeyFeedback.value = false; }, 2000);
    }
}

function onWebhookSecretRotated(secret) {
    revealedWebhookSecret.value = secret;
    showWebhookSecretModal.value = true;
}
</script>

<template>
    <div class="mx-auto w-full min-w-0 max-w-6xl space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">API</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Chaves de integração e webhooks outbound.
                </p>
            </div>
            <Button as="a" href="/docs/api-pagamentos" target="_blank" rel="noopener noreferrer" variant="outline" size="sm" class="inline-flex w-fit shrink-0 items-center gap-2">
                <FileText class="h-4 w-4" />
                Documentação completa
            </Button>
        </div>

        <div v-if="flashSuccess" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200">
            {{ flashSuccess }}
        </div>
        <div v-if="flashError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200">
            {{ flashError }}
        </div>

        <nav class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80" aria-label="API">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                :class="[
                    'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                    activeTab === tab.id
                        ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                ]"
                @click="setTab(tab.id)"
            >
                <component :is="tab.icon" class="h-4 w-4 shrink-0" />
                {{ tab.label }}
            </button>
        </nav>

        <!-- Tab: Chaves -->
        <div v-show="activeTab === 'chaves'" class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Crie várias chaves para ambientes diferentes. O secret completo só aparece na criação ou após revelar.
                </p>
                <Button type="button" class="inline-flex shrink-0 items-center gap-2" @click="openCreateKey">
                    <Plus class="h-4 w-4" />
                    Criar chave
                </Button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50/80 text-xs uppercase tracking-wider text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3 font-medium">Nome</th>
                            <th class="px-5 py-3 font-medium">Chave pública</th>
                            <th class="px-5 py-3 font-medium">Secret</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Criado em</th>
                            <th class="px-5 py-3 font-medium text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="keyRow in keys" :key="keyRow.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                            <td class="px-5 py-4 font-medium text-zinc-900 dark:text-white">{{ keyRow.name }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <code class="font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ keyRow.public_key_masked }}</code>
                                    <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="copyText(`pub-${keyRow.id}`, keyRow.public_key)">
                                        <Check v-if="copyDone[`pub-${keyRow.id}`]" class="h-3.5 w-3.5 text-emerald-600" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <template v-if="keyRow.is_active && keyRow.can_reveal_secret">
                                    <div v-if="revealedSecrets[keyRow.id]" class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <code class="max-w-[200px] truncate font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ revealedSecrets[keyRow.id] }}</code>
                                            <button
                                                type="button"
                                                class="shrink-0 rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                                title="Copiar secret"
                                                @click="copyRevealedSecret(keyRow)"
                                            >
                                                <Check v-if="copyDone[`sec-${keyRow.id}`]" class="h-3.5 w-3.5 text-emerald-600" />
                                                <Copy v-else class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                        <button type="button" class="text-xs text-zinc-500 hover:underline" @click="hideRevealed(keyRow.id)">Ocultar</button>
                                    </div>
                                    <template v-else>
                                        <div class="flex items-center gap-2">
                                            <code class="font-mono text-xs text-zinc-500">{{ maskSecret() }}</code>
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                                :disabled="revealLoading[keyRow.id]"
                                                @click="revealSecret(keyRow)"
                                            >
                                                {{ revealLoading[keyRow.id] ? '…' : 'Revelar' }}
                                            </button>
                                        </div>
                                        <p v-if="revealErrors[keyRow.id]" class="text-xs text-red-600">{{ revealErrors[keyRow.id] }}</p>
                                    </template>
                                </template>
                                <span v-else class="text-zinc-400">—</span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center gap-1.5 text-xs font-medium" :class="keyRow.is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-zinc-500'">
                                    <span class="h-2 w-2 rounded-full" :class="keyRow.is_active ? 'bg-emerald-500' : 'bg-zinc-400'" />
                                    {{ keyRow.is_active ? 'Ativa' : 'Revogada' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-400">{{ formatDate(keyRow.created_at) }}</td>
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="Editar" @click="openEditKey(keyRow)">
                                        <Settings2 class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="Rotacionar" @click="regenerateKey(keyRow)">
                                        <RefreshCw class="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                        :title="keyRow.is_active ? 'Revogar' : 'Ativar'"
                                        @click="toggleKeyActive(keyRow)"
                                    >
                                        <EyeOff v-if="keyRow.is_active" class="h-4 w-4" />
                                        <Eye v-else class="h-4 w-4" />
                                    </button>
                                    <button
                                        v-if="keyRow.can_delete"
                                        type="button"
                                        class="rounded-lg p-2 text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                        title="Remover"
                                        @click="deleteKey(keyRow)"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab: Webhooks -->
        <div v-show="activeTab === 'webhooks'" class="space-y-4">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Receba POST HTTPS assinados (HMAC) quando pagamentos e saques mudarem de estado.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="outline" size="sm" @click="router.reload({ only: ['webhook', 'recent_deliveries'] })">
                        <RefreshCw class="mr-2 h-4 w-4" />
                        Atualizar
                    </Button>
                    <Button as="a" href="/docs/api-pagamentos" target="_blank" variant="outline" size="sm">
                        <FileText class="mr-2 h-4 w-4" />
                        Como integrar
                    </Button>
                    <Button v-if="!webhook.configured" type="button" class="inline-flex items-center gap-2" @click="webhookSidebarOpen = true">
                        <Plus class="h-4 w-4" />
                        Adicionar endpoint
                    </Button>
                </div>
            </div>

            <ApiWebhookCard
                v-if="webhook.configured"
                :application-id="application.id"
                :webhook="webhook"
                :event-catalog="webhook_event_catalog"
                :recent-deliveries="recent_deliveries"
                @edit="webhookSidebarOpen = true"
                @rotated-secret="onWebhookSecretRotated"
            />

            <div
                v-else
                class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600"
            >
                <Webhook class="h-10 w-10 text-zinc-400" />
                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">Nenhum endpoint configurado.</p>
                <Button type="button" class="mt-4 inline-flex items-center gap-2" @click="webhookSidebarOpen = true">
                    <Plus class="h-4 w-4" />
                    Adicionar endpoint
                </Button>
            </div>
        </div>

        <ApiKeySidebar
            :open="keySidebarOpen"
            :application-id="application.id"
            :available-scopes="available_scopes"
            :scope-catalog="scope_catalog"
            :editing-key="editingKey"
            @close="keySidebarOpen = false"
            @saved="keySidebarOpen = false"
        />

        <ApiWebhookSidebar
            :open="webhookSidebarOpen"
            :application-id="application.id"
            :webhook="webhook"
            :event-catalog="webhook_event_catalog"
            :webhook-secret-mask="webhook_secret_mask"
            @close="webhookSidebarOpen = false"
            @saved="webhookSidebarOpen = false"
        />

        <!-- Modal: credenciais (cópia única) -->
        <Teleport to="body">
            <div v-show="showKeyModal && revealedSecretKey" class="fixed inset-0 z-[100001] flex items-center justify-center p-4" aria-modal="true" role="dialog">
                <div class="fixed inset-0 bg-zinc-900/60" @click="showKeyModal = false" />
                <div class="relative max-w-lg w-full rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="flex items-center gap-2 text-lg font-semibold text-zinc-900 dark:text-white">
                            <KeyRound class="h-5 w-5 text-amber-500" />
                            Suas chaves de API
                        </h2>
                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="showKeyModal = false">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        Copie e guarde em local seguro. Depois use <strong>Revelar</strong> na tabela.
                    </p>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <p class="mb-1 text-xs uppercase tracking-wide text-zinc-500">Public key</p>
                            <div class="flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate text-sm font-mono">{{ revealedPublicKey }}</code>
                                <Button type="button" size="sm" variant="outline" @click="copyKeyModal(revealedPublicKey)">Copiar</Button>
                            </div>
                        </div>
                        <div class="rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                            <p class="mb-1 text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Secret key</p>
                            <div class="flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate text-sm font-mono">{{ revealedSecretKey }}</code>
                                <Button type="button" size="sm" variant="outline" @click="copyKeyModal(revealedSecretKey)">
                                    {{ copyKeyFeedback ? 'Copiado!' : 'Copiar' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                    <Button class="mt-4 w-full" @click="showKeyModal = false">Entendi</Button>
                </div>
            </div>
        </Teleport>

        <!-- Modal: webhook secret -->
        <Teleport to="body">
            <div v-show="showWebhookSecretModal && revealedWebhookSecret" class="fixed inset-0 z-[100001] flex items-center justify-center p-4" aria-modal="true" role="dialog">
                <div class="fixed inset-0 bg-zinc-900/60" @click="showWebhookSecretModal = false" />
                <div class="relative max-w-lg w-full rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Signing secret do webhook</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Copie agora. Não será exibido novamente.</p>
                    <code class="mt-4 block break-all rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-mono dark:border-amber-900/50 dark:bg-amber-950/30">{{ revealedWebhookSecret }}</code>
                    <div class="mt-4 flex gap-2">
                        <Button type="button" variant="outline" class="flex-1" @click="copyKeyModal(revealedWebhookSecret)">Copiar</Button>
                        <Button type="button" class="flex-1" @click="showWebhookSecretModal = false">Fechar</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
