<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { LoaderCircle, Play, RotateCcw, Eye, EyeOff } from 'lucide-vue-next';
import { useDocApiClient } from '@/composables/useDocApiClient';
import { apiPlaygroundGroups, apiPlaygroundOperations } from '@/Pages/Docs/apiPagamentosData';

const STORAGE_ORDER_ID = 'doc_api_playground_last_order_id';
const STORAGE_WITHDRAWAL_ID = 'doc_api_playground_last_withdrawal_id';

const props = defineProps({
    baseUrl: { type: String, default: '' },
    initialOpId: { type: String, default: '' },
});

const client = useDocApiClient(computed(() => props.baseUrl));
const {
    authMode,
    publicKey,
    secretKey,
    legacyToken,
    loading,
    lastResponse,
    apiBase,
    sendRequest,
} = client;

const activeGroup = ref('pix');
const selectedOpId = ref('post-payments-pix');
const bodyJson = ref('');
const bodyError = ref('');
const extraHeaders = ref([{ key: '', value: '', enabled: true }]);
const pathParams = ref({});
const showSecret = ref(false);
const localError = ref('');

const selectedOp = computed(() => apiPlaygroundOperations.find((o) => o.id === selectedOpId.value) ?? apiPlaygroundOperations[0]);

const operationsInGroup = computed(() =>
    apiPlaygroundOperations.filter((o) => o.group === activeGroup.value)
);

const displayUrl = computed(() => {
    let path = selectedOp.value?.path ?? '';
    for (const [key, val] of Object.entries(pathParams.value)) {
        path = path.replace(`{${key}}`, val || `{${key}}`);
    }

    return `${apiBase.value}${path}`;
});

const methodClass = computed(() => {
    const m = selectedOp.value?.method ?? 'GET';
    if (m === 'GET') {
        return 'bg-emerald-500/20 text-emerald-300';
    }
    if (m === 'POST') {
        return 'bg-sky-500/20 text-sky-300';
    }
    if (m === 'PUT' || m === 'PATCH') {
        return 'bg-amber-500/20 text-amber-300';
    }

    return 'bg-zinc-500/20 text-zinc-300';
});

const hasBody = computed(() => {
    const m = selectedOp.value?.method ?? 'GET';

    return ['POST', 'PUT', 'PATCH'].includes(m);
});

function resetOperationState(op) {
    pathParams.value = {};
    if (op.pathFields?.length) {
        for (const field of op.pathFields) {
            if (field.key === 'order_id') {
                pathParams.value[field.key] = sessionStorage.getItem(STORAGE_ORDER_ID) ?? '';
            } else if (field.key === 'id') {
                pathParams.value[field.key] = sessionStorage.getItem(STORAGE_WITHDRAWAL_ID) ?? '';
            } else {
                pathParams.value[field.key] = '';
            }
        }
    }

    const defaultHeaders = op.defaultHeaders ?? {};
    extraHeaders.value = Object.entries(defaultHeaders).map(([key, value]) => ({
        key,
        value: String(value),
        enabled: true,
    }));
    if (extraHeaders.value.length === 0) {
        extraHeaders.value = [{ key: '', value: '', enabled: true }];
    }

    if (op.defaultBody != null) {
        bodyJson.value = JSON.stringify(op.defaultBody, null, 2);
    } else {
        bodyJson.value = '';
    }
    bodyError.value = '';
    localError.value = '';
}

function selectOperation(opId) {
    selectedOpId.value = opId;
    const op = apiPlaygroundOperations.find((o) => o.id === opId);
    if (op) {
        activeGroup.value = op.group;
        resetOperationState(op);
    }
}

function formatBodyJson() {
    bodyError.value = '';
    if (!bodyJson.value.trim()) {
        return;
    }
    try {
        bodyJson.value = JSON.stringify(JSON.parse(bodyJson.value), null, 2);
    } catch (e) {
        bodyError.value = e?.message || 'JSON inválido';
    }
}

function parseBody() {
    if (!hasBody.value || !bodyJson.value.trim()) {
        return null;
    }
    try {
        return JSON.parse(bodyJson.value);
    } catch (e) {
        bodyError.value = e?.message || 'JSON inválido';

        return undefined;
    }
}

function buildExtraHeaders() {
    const out = {};
    for (const row of extraHeaders.value) {
        if (!row.enabled || !row.key.trim()) {
            continue;
        }
        out[row.key.trim()] = row.value;
    }

    return out;
}

async function send() {
    localError.value = '';
    bodyError.value = '';

    const op = selectedOp.value;
    if (!op) {
        return;
    }

    for (const field of op.pathFields ?? []) {
        if (!String(pathParams.value[field.key] ?? '').trim()) {
            localError.value = `Informe ${field.label}.`;

            return;
        }
    }

    let body = null;
    if (hasBody.value) {
        body = parseBody();
        if (body === undefined) {
            return;
        }
    }

    const response = await sendRequest({
        method: op.method,
        path: op.path,
        body,
        headers: buildExtraHeaders(),
        pathParams: pathParams.value,
    });

    if (response.ok && op.id === 'post-payments-pix' && response.data?.order_id) {
        sessionStorage.setItem(STORAGE_ORDER_ID, String(response.data.order_id));
        pathParams.value.order_id = String(response.data.order_id);
    }

    if (response.ok && op.id === 'post-withdrawals' && response.data?.withdrawal_id) {
        sessionStorage.setItem(STORAGE_WITHDRAWAL_ID, String(response.data.withdrawal_id));
        pathParams.value.id = String(response.data.withdrawal_id);
    }
}

function onKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        send();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    if (props.initialOpId && apiPlaygroundOperations.some((o) => o.id === props.initialOpId)) {
        selectOperation(props.initialOpId);
    } else {
        resetOperationState(selectedOp.value);
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});

watch(selectedOpId, (id) => {
    const op = apiPlaygroundOperations.find((o) => o.id === id);
    if (op) {
        activeGroup.value = op.group;
        resetOperationState(op);
    }
});

watch(activeGroup, (group) => {
    const first = apiPlaygroundOperations.find((o) => o.group === group);
    if (first && !operationsInGroup.value.some((o) => o.id === selectedOpId.value)) {
        selectedOpId.value = first.id;
    }
});
</script>

<template>
    <div class="doc-api-playground overflow-hidden rounded-xl border border-white/10 bg-zinc-900/40">
        <!-- Group tabs + operation selector -->
        <div class="border-b border-white/10 bg-zinc-950/60 px-4 py-3">
            <div class="mb-3 flex flex-wrap gap-2">
                <button
                    v-for="g in apiPlaygroundGroups"
                    :key="g.id"
                    type="button"
                    class="rounded-full px-3 py-1.5 text-sm font-medium transition"
                    :class="
                        activeGroup === g.id
                            ? 'bg-teal-600 text-white'
                            : 'bg-white/5 text-zinc-400 hover:bg-white/10 hover:text-white'
                    "
                    @click="activeGroup = g.id"
                >
                    {{ g.label }}
                </button>
            </div>
            <select
                v-model="selectedOpId"
                class="w-full rounded-lg border border-white/10 bg-zinc-900 px-3 py-2 text-sm text-white focus:border-teal-500/50 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
            >
                <option v-for="op in operationsInGroup" :key="op.id" :value="op.id">
                    {{ op.method }} {{ op.path }} — {{ op.label }}
                </option>
            </select>
            <p v-if="selectedOp?.description" class="mt-2 text-xs text-zinc-500">{{ selectedOp.description }}</p>
        </div>

        <!-- Top bar: method + URL + Send -->
        <div class="flex flex-wrap items-center gap-2 border-b border-white/10 bg-zinc-950/40 px-4 py-3">
            <span class="rounded-md px-2 py-1 font-mono text-xs font-bold uppercase" :class="methodClass">
                {{ selectedOp?.method }}
            </span>
            <code class="min-w-0 flex-1 truncate font-mono text-xs text-zinc-300 sm:text-sm">{{ displayUrl }}</code>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                :disabled="loading"
                @click="send"
            >
                <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin" />
                <Play v-else class="h-4 w-4" />
                Send
            </button>
        </div>

        <div class="grid lg:grid-cols-2 lg:divide-x lg:divide-white/10">
            <!-- Request pane -->
            <div class="space-y-0 divide-y divide-white/10 p-4">
                <!-- Authentication -->
                <section class="pb-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">Authentication</h3>
                    <div class="mb-3 flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium"
                            :class="authMode === 'keypair' ? 'bg-teal-500/20 text-teal-300' : 'bg-white/5 text-zinc-400'"
                            @click="authMode = 'keypair'"
                        >
                            Public + Secret
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium"
                            :class="authMode === 'legacy' ? 'bg-teal-500/20 text-teal-300' : 'bg-white/5 text-zinc-400'"
                            @click="authMode = 'legacy'"
                        >
                            Bearer (legado)
                        </button>
                    </div>
                    <div v-if="authMode === 'keypair'" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs text-zinc-500">X-Public-Key</label>
                            <input
                                v-model="publicKey"
                                type="text"
                                placeholder="gpk_..."
                                autocomplete="off"
                                class="w-full rounded-lg border border-white/10 bg-zinc-950 px-3 py-2 font-mono text-xs text-white placeholder-zinc-600 focus:border-teal-500/50 focus:outline-none"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-zinc-500">X-Secret-Key</label>
                            <div class="relative">
                                <input
                                    v-model="secretKey"
                                    :type="showSecret ? 'text' : 'password'"
                                    placeholder="gsk_..."
                                    autocomplete="off"
                                    class="w-full rounded-lg border border-white/10 bg-zinc-950 px-3 py-2 pr-10 font-mono text-xs text-white placeholder-zinc-600 focus:border-teal-500/50 focus:outline-none"
                                />
                                <button
                                    type="button"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-zinc-300"
                                    @click="showSecret = !showSecret"
                                >
                                    <EyeOff v-if="showSecret" class="h-4 w-4" />
                                    <Eye v-else class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <label class="mb-1 block text-xs text-zinc-500">Authorization Bearer</label>
                        <input
                            v-model="legacyToken"
                            type="password"
                            placeholder="getfy_..."
                            autocomplete="off"
                            class="w-full rounded-lg border border-white/10 bg-zinc-950 px-3 py-2 font-mono text-xs text-white placeholder-zinc-600 focus:border-teal-500/50 focus:outline-none"
                        />
                    </div>
                </section>

                <!-- Path params -->
                <section v-if="selectedOp?.pathFields?.length" class="py-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">Path parameters</h3>
                    <div class="space-y-2">
                        <div v-for="field in selectedOp.pathFields" :key="field.key">
                            <label class="mb-1 block text-xs text-zinc-500">{{ field.label }}</label>
                            <input
                                v-model="pathParams[field.key]"
                                type="text"
                                :placeholder="field.placeholder"
                                class="w-full rounded-lg border border-white/10 bg-zinc-950 px-3 py-2 font-mono text-xs text-white placeholder-zinc-600 focus:border-teal-500/50 focus:outline-none"
                            />
                        </div>
                    </div>
                </section>

                <!-- Headers -->
                <section class="py-4">
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">Headers</h3>
                    <div class="space-y-2">
                        <div
                            v-for="(row, idx) in extraHeaders"
                            :key="idx"
                            class="flex items-center gap-2"
                        >
                            <input v-model="row.enabled" type="checkbox" class="rounded border-zinc-600" />
                            <input
                                v-model="row.key"
                                type="text"
                                placeholder="Header"
                                class="min-w-0 flex-1 rounded border border-white/10 bg-zinc-950 px-2 py-1.5 font-mono text-xs text-white"
                            />
                            <input
                                v-model="row.value"
                                type="text"
                                placeholder="Value"
                                class="min-w-0 flex-[2] rounded border border-white/10 bg-zinc-950 px-2 py-1.5 font-mono text-xs text-white"
                            />
                        </div>
                    </div>
                </section>

                <!-- Body -->
                <section v-if="hasBody" class="pt-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Request body (JSON)</h3>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-400 hover:bg-white/5 hover:text-white"
                                @click="formatBodyJson"
                            >
                                Format
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs text-zinc-400 hover:bg-white/5 hover:text-white"
                                @click="resetOperationState(selectedOp)"
                            >
                                <RotateCcw class="h-3 w-3" />
                                Reset
                            </button>
                        </div>
                    </div>
                    <textarea
                        v-model="bodyJson"
                        rows="14"
                        spellcheck="false"
                        class="w-full rounded-lg border border-white/10 bg-zinc-950 p-3 font-mono text-xs leading-relaxed text-zinc-200 focus:border-teal-500/50 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
                    />
                    <p v-if="bodyError" class="mt-2 text-xs text-red-400">{{ bodyError }}</p>
                </section>

                <p v-if="localError" class="pt-2 text-sm text-red-400">{{ localError }}</p>
            </div>

            <!-- Response pane -->
            <div class="flex min-h-[320px] flex-col bg-zinc-950/30 p-4 lg:min-h-[480px]">
                <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-500">Response</h3>

                <div
                    v-if="!lastResponse"
                    class="flex flex-1 flex-col items-center justify-center rounded-lg border border-dashed border-white/10 py-12 text-center"
                >
                    <pre class="font-mono text-xs leading-tight text-zinc-600" aria-hidden="true">┌──────────────┐
│   Response   │
│              │
└──────────────┘</pre>
                    <p class="mt-4 text-sm text-zinc-500">Send a request to see the response</p>
                    <p class="mt-1 text-xs text-zinc-600">Ctrl+Enter</p>
                </div>

                <div v-else class="flex min-h-0 flex-1 flex-col">
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                        <span
                            class="rounded-md px-2 py-0.5 font-mono text-xs font-bold"
                            :class="lastResponse.ok ? 'bg-emerald-500/20 text-emerald-300' : 'bg-red-500/20 text-red-300'"
                        >
                            {{ lastResponse.status ?? 'ERR' }}
                        </span>
                        <span class="font-mono text-xs text-zinc-500">{{ lastResponse.method }} {{ lastResponse.path }}</span>
                        <span class="text-xs text-zinc-600">{{ lastResponse.durationMs }} ms</span>
                    </div>
                    <p v-if="lastResponse.error" class="mb-3 text-sm text-red-400">
                        {{ lastResponse.error }}
                    </p>
                    <div
                        v-if="lastResponse.data?.copy_paste"
                        class="mb-3 rounded-lg border border-teal-500/30 bg-teal-500/10 p-3"
                    >
                        <p class="text-xs font-semibold uppercase tracking-wider text-teal-400">PIX copia e cola</p>
                        <p class="mt-2 break-all font-mono text-xs text-teal-100">
                            {{ lastResponse.data.copy_paste }}
                        </p>
                    </div>
                    <pre
                        class="min-h-0 flex-1 overflow-auto rounded-lg border border-white/10 bg-zinc-950 p-4 font-mono text-xs leading-relaxed text-zinc-300"
                    >{{ lastResponse.data !== null ? JSON.stringify(lastResponse.data, null, 2) : '—' }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
