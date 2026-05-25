<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import {
    Banknote,
    CreditCard,
    FileText,
    FlaskConical,
    LoaderCircle,
    Search,
    ShoppingCart,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
});

const publicKey = ref('');
const secretKey = ref('');
const orderId = ref('');
const loading = ref('');
const result = ref({ endpoint: '', status: null, data: null, error: null });

const apiBase = computed(() =>
    props.baseUrl ? `${props.baseUrl.replace(/\/$/, '')}/api/v1` : '/api/v1'
);

const sessionBody = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 10,
    currency: 'BRL',
    expires_in: 30,
});
const pixBody = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 5,
    currency: 'BRL',
});
const boletoBody = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 25,
    currency: 'BRL',
});
const cardBody = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 15,
    currency: 'BRL',
    card: { payment_token: '', card_mask: '**** 1234' },
});

function authHeaders() {
    const h = { 'Content-Type': 'application/json' };
    if (publicKey.value.trim() && secretKey.value.trim()) {
        h['X-Public-Key'] = publicKey.value.trim();
        h['X-Secret-Key'] = secretKey.value.trim();
    }
    return h;
}

async function request(method, path, body = null) {
    result.value = { endpoint: '', status: null, data: null, error: null };
    loading.value = path;
    try {
        const opts = { method, headers: authHeaders() };
        if (body && (method === 'POST' || method === 'PUT')) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(`${apiBase.value}${path}`, opts);
        const text = await res.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch {
            data = text;
        }
        result.value = {
            endpoint: `${method} ${path}`,
            status: res.status,
            data,
            error: res.ok ? null : data?.message || res.statusText,
        };
    } catch (e) {
        result.value = {
            endpoint: `${method} ${path}`,
            status: null,
            data: null,
            error: e?.message || 'Erro de rede',
        };
    } finally {
        loading.value = '';
    }
}

function isLoading(path) {
    return loading.value === path;
}

function consultOrder() {
    const id = orderId.value.trim();
    if (!id) {
        result.value = { endpoint: '', status: null, data: null, error: 'Informe o ID do pedido.' };
        return;
    }
    request('GET', `/payments/${encodeURIComponent(id)}`);
}
</script>

<template>
    <div class="py-8">
        <div class="mb-8 flex flex-wrap items-center gap-4">
            <Link
                href="/docs/api-pagamentos"
                class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-zinc-400 transition hover:border-teal-500/30 hover:bg-white/10 hover:text-white"
            >
                ← Documentação
            </Link>
            <Link
                href="/aplicacoes-api"
                class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-zinc-400 transition hover:border-teal-500/30 hover:bg-white/10 hover:text-white"
            >
                Chaves da API
            </Link>
        </div>

        <div class="mb-8 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200/90">
            <strong>Página de testes.</strong>
            Obtenha o par de chaves em
            <Link href="/aplicacoes-api" class="underline hover:text-amber-100">Chaves da API</Link>.
            Deixe <strong>IPs permitidos</strong> vazio ou inclua seu IP na integração.
        </div>

        <div class="mb-8 grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-zinc-500"
                    >Public Key</label
                >
                <input
                    v-model="publicKey"
                    type="password"
                    placeholder="gpk_xxxx..."
                    autocomplete="off"
                    class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white placeholder-zinc-500 focus:border-teal-500/50 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-zinc-500"
                    >Secret Key</label
                >
                <input
                    v-model="secretKey"
                    type="password"
                    placeholder="gsk_xxxx..."
                    autocomplete="off"
                    class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white placeholder-zinc-500 focus:border-teal-500/50 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-zinc-500">Base URL</label>
                <input
                    :value="apiBase"
                    readonly
                    class="w-full rounded-lg border border-white/10 bg-zinc-900/80 px-3 py-2 font-mono text-sm text-zinc-400"
                />
            </div>
        </div>

        <div class="space-y-8">
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <ShoppingCart class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Checkout Pro – Criar sessão</h2>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="request('POST', '/checkout/sessions', sessionBody)"
                >
                    <LoaderCircle v-if="isLoading('/checkout/sessions')" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <CreditCard class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">PIX – Criar cobrança</h2>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="request('POST', '/payments/pix', pixBody)"
                >
                    <LoaderCircle v-if="isLoading('/payments/pix')" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Banknote class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Boleto – Criar cobrança</h2>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="request('POST', '/payments/boleto', boletoBody)"
                >
                    <LoaderCircle v-if="isLoading('/payments/boleto')" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <CreditCard class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Cartão – Criar pagamento</h2>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="request('POST', '/payments/card', cardBody)"
                >
                    <LoaderCircle v-if="isLoading('/payments/card')" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Search class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Consultar status</h2>
                </div>
                <div class="flex flex-wrap items-end gap-4">
                    <div class="min-w-[120px]">
                        <label class="mb-1 block text-xs text-zinc-500">Order ID</label>
                        <input
                            v-model="orderId"
                            type="text"
                            placeholder="Ex: 123"
                            class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white"
                        />
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                        :disabled="!!loading"
                        @click="consultOrder"
                    >
                        <LoaderCircle
                            v-if="loading && loading.startsWith('/payments/')"
                            class="h-4 w-4 animate-spin"
                        />
                        <FileText v-else class="h-4 w-4" />
                        Consultar
                    </button>
                </div>
            </section>
        </div>

        <section
            v-if="result.endpoint || result.error"
            class="mt-10 rounded-xl border border-white/10 bg-zinc-900/80 p-6"
        >
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">Resposta</h3>
            <p v-if="result.endpoint" class="mb-2 font-mono text-sm text-zinc-400">
                {{ result.endpoint }}
                <span
                    v-if="result.status !== null"
                    class="ml-2"
                    :class="result.status >= 200 && result.status < 300 ? 'text-emerald-400' : 'text-amber-400'"
                >
                    {{ result.status }}
                </span>
            </p>
            <p v-if="result.error" class="mb-3 text-sm text-red-400">{{ result.error }}</p>
            <pre
                class="overflow-x-auto rounded-lg border border-white/10 bg-zinc-950 p-4 font-mono text-xs text-zinc-300"
                >{{ result.data !== null ? JSON.stringify(result.data, null, 2) : '—' }}</pre
            >
        </section>
    </div>
</template>
