<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import { FlaskConical, ShoppingCart, CreditCard, Banknote, FileText, Search, Loader2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
});

const apiKey = ref('');
const loading = ref('');
const response = ref({ endpoint: '', status: null, data: null, error: null });

const apiBase = computed(() => (props.baseUrl ? `${props.baseUrl.replace(/\/$/, '')}/api/v1` : '/api/v1'));

function headers() {
    const h = { 'Content-Type': 'application/json' };
    if (apiKey.value.trim()) {
        h['Authorization'] = `Bearer ${apiKey.value.trim()}`;
    }
    return h;
}

async function request(method, path, body = null) {
    response.value = { endpoint: '', status: null, data: null, error: null };
    loading.value = path;
    try {
        const opts = { method, headers: headers() };
        if (body && (method === 'POST' || method === 'PUT')) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(apiBase.value + path, opts);
        const text = await res.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch {
            data = text;
        }
        response.value = {
            endpoint: method + ' ' + path,
            status: res.status,
            data,
            error: !res.ok ? (data?.message || res.statusText) : null,
        };
    } catch (err) {
        response.value = {
            endpoint: method + ' ' + path,
            status: null,
            data: null,
            error: err.message || 'Erro de rede',
        };
    } finally {
        loading.value = '';
    }
}

// Checkout Pro
const sessionForm = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 10.0,
    currency: 'BRL',
    expires_in: 30,
});
function testCheckoutSession() {
    request('POST', '/checkout/sessions', sessionForm.value);
}

// PIX
const pixForm = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 5.0,
    currency: 'BRL',
});
function testPix() {
    request('POST', '/payments/pix', pixForm.value);
}

// Boleto
const boletoForm = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 25.0,
    currency: 'BRL',
});
function testBoleto() {
    request('POST', '/payments/boleto', boletoForm.value);
}

// Cartão (token geralmente vem do gateway; para teste pode falhar sem token válido)
const cardForm = ref({
    customer: { email: 'teste@exemplo.com', name: 'Cliente Teste', cpf: '', phone: '' },
    amount: 15.0,
    currency: 'BRL',
    card: { payment_token: '', card_mask: '**** 1234' },
});
function testCard() {
    request('POST', '/payments/card', cardForm.value);
}

// Consultar pedido
const orderId = ref('');
function testGetOrder() {
    const id = orderId.value.trim();
    if (!id) {
        response.value = { endpoint: '', status: null, data: null, error: 'Informe o ID do pedido.' };
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
                Aplicações API
            </Link>
        </div>

        <div class="mb-8 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200/90">
            <strong>Teste provisório.</strong> Use uma aplicação ativa em
            <Link href="/aplicacoes-api" class="underline hover:text-amber-100">Aplicações API</Link>
            e cole a API key abaixo. Deixe <strong>IPs permitidos</strong> vazio ou inclua seu IP para não receber 403.
        </div>

        <div class="mb-8 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wider text-zinc-500">API Key</label>
                <input
                    v-model="apiKey"
                    type="password"
                    placeholder="getfy_xxxx..."
                    class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white placeholder-zinc-500 focus:border-teal-500/50 focus:outline-none focus:ring-1 focus:ring-teal-500/30"
                    autocomplete="off"
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
            <!-- Checkout Pro -->
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <ShoppingCart class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Checkout Pro – Criar sessão</h2>
                </div>
                <p class="mb-4 text-sm text-zinc-400">
                    POST /checkout/sessions — retorna <code class="rounded bg-white/10 px-1 py-0.5">checkout_url</code> para o cliente concluir o pagamento.
                </p>
                <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">E-mail</label>
                        <input v-model="sessionForm.customer.email" type="email" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Nome</label>
                        <input v-model="sessionForm.customer.name" type="text" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Valor</label>
                        <input v-model.number="sessionForm.amount" type="number" step="0.01" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Moeda</label>
                        <select v-model="sessionForm.currency" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="BRL">BRL</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="testCheckoutSession"
                >
                    <Loader2 v-if="loading === '/checkout/sessions'" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <!-- PIX -->
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <CreditCard class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">PIX – Criar cobrança</h2>
                </div>
                <p class="mb-4 text-sm text-zinc-400">
                    POST /payments/pix — retorna <code class="rounded bg-white/10 px-1 py-0.5">qrcode</code> e <code class="rounded bg-white/10 px-1 py-0.5">copy_paste</code>.
                </p>
                <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">E-mail</label>
                        <input v-model="pixForm.customer.email" type="email" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Nome</label>
                        <input v-model="pixForm.customer.name" type="text" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Valor</label>
                        <input v-model.number="pixForm.amount" type="number" step="0.01" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Moeda</label>
                        <select v-model="pixForm.currency" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="BRL">BRL</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="testPix"
                >
                    <Loader2 v-if="loading === '/payments/pix'" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <!-- Boleto -->
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Banknote class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Boleto – Criar cobrança</h2>
                </div>
                <p class="mb-4 text-sm text-zinc-400">
                    POST /payments/boleto — retorna <code class="rounded bg-white/10 px-1 py-0.5">barcode</code>, <code class="rounded bg-white/10 px-1 py-0.5">pdf_url</code>, <code class="rounded bg-white/10 px-1 py-0.5">expire_at</code>.
                </p>
                <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">E-mail</label>
                        <input v-model="boletoForm.customer.email" type="email" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Nome</label>
                        <input v-model="boletoForm.customer.name" type="text" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Valor</label>
                        <input v-model.number="boletoForm.amount" type="number" step="0.01" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Moeda</label>
                        <select v-model="boletoForm.currency" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="BRL">BRL</option>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="testBoleto"
                >
                    <Loader2 v-if="loading === '/payments/boleto'" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <!-- Cartão -->
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <CreditCard class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Cartão – Criar pagamento</h2>
                </div>
                <p class="mb-4 text-sm text-zinc-400">
                    POST /payments/card — exige <code class="rounded bg-white/10 px-1 py-0.5">card.payment_token</code> (token do gateway). Para teste real use o checkout que gera o token.
                </p>
                <div class="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">E-mail</label>
                        <input v-model="cardForm.customer.email" type="email" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Nome</label>
                        <input v-model="cardForm.customer.name" type="text" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Valor</label>
                        <input v-model.number="cardForm.amount" type="number" step="0.01" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-zinc-500">Payment token (gateway)</label>
                        <input v-model="cardForm.card.payment_token" type="text" placeholder="Opcional para testar 422" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white placeholder-zinc-500" />
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                    :disabled="!!loading"
                    @click="testCard"
                >
                    <Loader2 v-if="loading === '/payments/card'" class="h-4 w-4 animate-spin" />
                    <FlaskConical v-else class="h-4 w-4" />
                    Enviar
                </button>
            </section>

            <!-- Consultar pedido -->
            <section class="rounded-xl border border-white/10 bg-white/[0.02] p-6">
                <div class="mb-4 flex items-center gap-2">
                    <Search class="h-5 w-5 text-teal-400" />
                    <h2 class="text-lg font-semibold text-white">Consultar status do pedido</h2>
                </div>
                <p class="mb-4 text-sm text-zinc-400">
                    GET /payments/{order_id} — use o <code class="rounded bg-white/10 px-1 py-0.5">order_id</code> retornado em PIX/Boleto/Cartão.
                </p>
                <div class="mb-4 flex flex-wrap items-end gap-4">
                    <div class="min-w-[120px]">
                        <label class="mb-1 block text-xs text-zinc-500">Order ID</label>
                        <input v-model="orderId" type="text" placeholder="Ex: 123" class="input-doc w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-sm text-white placeholder-zinc-500" />
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-500 disabled:opacity-50"
                        :disabled="!!loading"
                        @click="testGetOrder"
                    >
                        <Loader2 v-if="loading && loading.startsWith('/payments/') && !['/payments/pix','/payments/card','/payments/boleto'].includes(loading)" class="h-4 w-4 animate-spin" />
                        <FileText v-else class="h-4 w-4" />
                        Consultar
                    </button>
                </div>
            </section>
        </div>

        <!-- Resposta -->
        <section v-if="response.endpoint || response.error" class="mt-10 rounded-xl border border-white/10 bg-zinc-900/80 p-6">
            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wider text-zinc-500">Resposta</h3>
            <p v-if="response.endpoint" class="mb-2 font-mono text-sm text-zinc-400">
                {{ response.endpoint }}
                <span v-if="response.status !== null" class="ml-2" :class="response.status >= 200 && response.status < 300 ? 'text-emerald-400' : 'text-amber-400'">
                    {{ response.status }}
                </span>
            </p>
            <p v-if="response.error" class="mb-3 text-sm text-red-400">
                {{ response.error }}
            </p>
            <pre class="overflow-x-auto rounded-lg border border-white/10 bg-zinc-950 p-4 font-mono text-xs text-zinc-300">{{ response.data !== null ? JSON.stringify(response.data, null, 2) : '—' }}</pre>
        </section>
    </div>
</template>
