<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import DocSection from '@/components/docs/DocSection.vue';
import DocEndpoint from '@/components/docs/DocEndpoint.vue';
import DocCode from '@/components/docs/DocCode.vue';
import DocTable from '@/components/docs/DocTable.vue';
import DocCallout from '@/components/docs/DocCallout.vue';
import {
    customerFields,
    endpointSummary,
    errorCodes,
    fieldColumns,
    navSections,
    sessionFields,
    webhookEvents,
    whenToUse,
} from './apiPagamentosData';
import { ChevronRight, Menu, X } from 'lucide-vue-next';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
});

const apiBase = computed(() =>
    props.baseUrl ? `${props.baseUrl.replace(/\/$/, '')}/api/v1` : '/api/v1'
);
const hostExample = computed(() =>
    props.baseUrl ? props.baseUrl.replace(/^https?:\/\//, '').replace(/\/$/, '') : 'api.exemplo.com'
);
const siteBase = computed(() =>
    props.baseUrl ? props.baseUrl.replace(/\/$/, '') : 'https://api.exemplo.com'
);

const contentRef = ref(null);
const activeId = ref('');
const menuOpen = ref(false);

function scrollTo(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    menuOpen.value = false;
}

function setupObserver() {
    if (!contentRef.value) return;
    const nodes = contentRef.value.querySelectorAll('[id]');
    const observer = new IntersectionObserver(
        (entries) => {
            for (const e of entries) {
                if (e.isIntersecting && e.target.id) activeId.value = e.target.id;
            }
        },
        { rootMargin: '-80px 0px -70% 0px', threshold: 0 }
    );
    nodes.forEach((n) => n.id && observer.observe(n));
}

onMounted(() => nextTick(setupObserver));
</script>

<template>
    <div class="api-docs relative flex min-h-0 flex-col lg:flex-row lg:gap-12">
        <div
            v-if="menuOpen"
            class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"
            aria-hidden="true"
            @click="menuOpen = false"
        />

        <aside
            class="api-docs-sidebar fixed left-0 top-0 z-50 h-full w-72 shrink-0 border-r border-white/5 bg-zinc-900/98 shadow-2xl transition-transform duration-200 lg:static lg:z-auto lg:h-auto lg:w-64 lg:translate-x-0 lg:border-r lg:border-white/5 lg:bg-transparent lg:shadow-none"
            :class="menuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        >
            <div class="flex h-14 items-center justify-between border-b border-white/5 px-4 lg:hidden">
                <span class="text-sm font-semibold text-white">Menu</span>
                <button
                    type="button"
                    class="rounded-lg p-2 text-zinc-400 hover:bg-white/5 hover:text-white"
                    aria-label="Fechar menu"
                    @click="menuOpen = false"
                >
                    <X class="h-5 w-5" />
                </button>
            </div>
            <nav class="overflow-y-auto py-6 pl-4 pr-3 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]">
                <div class="space-y-8">
                    <div v-for="section in navSections" :key="section.title" class="space-y-2">
                        <div class="flex items-center gap-2 px-2 pb-1">
                            <component :is="section.icon" class="h-4 w-4 shrink-0 text-teal-400/90" />
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">{{
                                section.title
                            }}</span>
                        </div>
                        <ul class="space-y-0.5">
                            <li v-for="item in section.items" :key="item.id">
                                <a
                                    :href="`#${item.id}`"
                                    class="doc-nav-link flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition"
                                    :class="
                                        activeId === item.id
                                            ? 'bg-teal-500/15 text-teal-300'
                                            : 'text-zinc-300 hover:bg-white/5 hover:text-white'
                                    "
                                    @click.prevent="scrollTo(item.id)"
                                >
                                    {{ item.title }}
                                    <ChevronRight class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </aside>

        <div ref="contentRef" class="min-w-0 flex-1 lg:pl-0">
            <div
                class="sticky top-14 z-30 flex items-center justify-between border-b border-white/5 bg-zinc-900/95 px-4 py-3 backdrop-blur-sm lg:hidden"
            >
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-zinc-300 hover:bg-white/5 hover:text-white"
                    @click="menuOpen = true"
                >
                    <Menu class="h-5 w-5" />
                    Menu
                </button>
            </div>

            <div class="border-b border-white/5 px-4 pb-8 pt-6 lg:px-0 lg:pt-2">
                <div class="mx-auto max-w-3xl">
                    <h1 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">API PIX (Gateway)</h1>
                    <p class="mt-2 text-base leading-relaxed text-zinc-400">
                        Para <strong class="text-zinc-200">marketplaces, ERPs, SaaS e parceiros</strong>: crie cobranças
                        via API, exiba QR Code ou copia e cola, acompanhe status e receba webhooks.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="rounded-full bg-teal-500/20 px-3 py-1 text-xs font-medium text-teal-300">REST</span>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-zinc-400">JSON</span>
                        <Link
                            href="/docs/api-pagamentos/testar"
                            class="rounded-full border border-teal-500/40 bg-teal-500/10 px-3 py-1 text-xs font-medium text-teal-300 hover:bg-teal-500/20"
                        >
                            Testar API →
                        </Link>
                    </div>
                </div>
            </div>

            <div class="mx-auto max-w-3xl px-4 pt-8 lg:px-0">
                <div class="rounded-xl border border-teal-500/30 bg-teal-500/10 px-4 py-4">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <span class="text-xs font-semibold uppercase tracking-wider text-teal-400/90">Base URL</span>
                        <code class="text-sm text-zinc-200">{{ apiBase }}</code>
                    </div>
                    <p class="mt-2 text-sm text-zinc-400">
                        Autenticação:
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Public-Key</code>
                        e
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Secret-Key</code>.
                    </p>
                </div>
            </div>

            <article class="api-docs-content mx-auto max-w-3xl px-4 pb-24 pt-8 lg:px-6">
                <DocSection id="inicio-rapido" title="Início rápido">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Todas as rotas estão sob
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">/api/v1</code>.
                    </p>
                    <h3 class="doc-h3">Resumo dos endpoints</h3>
                    <DocTable
                        :columns="[
                            { key: 'method', label: 'Método' },
                            { key: 'endpoint', label: 'Endpoint' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="endpointSummary"
                    />
                </DocSection>

                <DocSection id="para-parceiros" title="Integração para parceiros">
                    <ol class="doc-ol">
                        <li>
                            O vendedor habilita a API e obtém o par em
                            <strong class="text-zinc-200">Chaves da API</strong>.
                        </li>
                        <li>
                            Seu backend chama
                            <code class="rounded bg-white/10 px-1 py-0.5 text-teal-300">POST /api/v1/payments/pix</code>.
                        </li>
                        <li>Exiba o QR Code ou copia e cola ao cliente final.</li>
                        <li>
                            Receba
                            <code class="rounded bg-white/10 px-1 py-0.5">order.completed</code>
                            no webhook.
                        </li>
                    </ol>
                </DocSection>

                <DocSection id="visao-geral" title="Visão geral">
                    <h3 class="doc-h3">Checkout transparente (PIX)</h3>
                    <ul class="doc-ul">
                        <li>QR Code em base64 e código copia e cola na resposta.</li>
                        <li>Consulte status com GET ou use webhooks.</li>
                    </ul>
                    <h3 class="doc-h3">Checkout Pro</h3>
                    <ul class="doc-ul">
                        <li>
                            Redirecione para
                            <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">checkout_url</code>.
                        </li>
                    </ul>
                </DocSection>

                <DocSection id="quando-usar" title="Modos de checkout">
                    <DocTable
                        :columns="[
                            { key: 'cenario', label: 'Cenário' },
                            { key: 'sugestao', label: 'Sugestão' },
                        ]"
                        :rows="whenToUse"
                    />
                </DocSection>

                <DocSection id="envio-api-key" title="Envio das chaves">
                    <ul class="doc-ul mb-6">
                        <li><code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Public-Key</code></li>
                        <li><code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Secret-Key</code></li>
                    </ul>
                    <DocCode label="http">
                        POST /api/v1/checkout/sessions HTTP/1.1
                        Host: {{ hostExample }}
                        X-Public-Key: gpk_xxxxxxxx
                        X-Secret-Key: gsk_xxxxxxxx
                        Content-Type: application/json
                    </DocCode>
                </DocSection>

                <DocSection id="obtencao-api-key" title="Obtenção das chaves">
                    <ol class="doc-ol">
                        <li>
                            Painel → <strong class="text-zinc-200">Chaves da API</strong> (
                            <code class="rounded bg-white/10 px-1 py-0.5">/aplicacoes-api</code>).
                        </li>
                        <li>Copie Public key e Secret key (revelar quando necessário).</li>
                    </ol>
                </DocSection>

                <DocSection id="seguranca" title="Segurança">
                    <ul class="doc-ul">
                        <li>Nunca exponha a Secret no frontend.</li>
                        <li>Use HTTPS em produção.</li>
                    </ul>
                </DocSection>

                <DocSection id="integracao-conta" title="Chaves e configuração">
                    <p class="text-zinc-400 leading-relaxed">
                        Cada conta tem um par Public + Secret. Configure webhook, IPs permitidos e URL de retorno no
                        painel.
                    </p>
                </DocSection>

                <DocSection id="processadora-webhooks" title="Webhooks">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Configure
                        <code class="rounded bg-white/10 px-1 py-0.5 text-teal-300">webhook_url</code>
                        na integração.
                    </p>
                </DocSection>

                <DocSection id="dados-comuns-customer" title="Dados comuns (customer)">
                    <DocTable :columns="fieldColumns" :rows="customerFields" />
                </DocSection>

                <DocSection id="post-payments-pix">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/payments/pix"
                        description="Cria cobrança PIX: pedido + QR Code e copia e cola."
                    >
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocCode label="json">
                            { "order_id": 456, "qrcode": "data:image/png;base64,...", "copy_paste": "00020126...",
                            "status": "pending" }
                        </DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="get-payments-order-id">
                    <DocEndpoint method="GET" path="/api/v1/payments/{order_id}" description="Consulta status do pedido.">
                        <DocCode label="json">{ "order_id": 456, "status": "completed", "amount": 97.90 }</DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-pix-cancel">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/pix/{order_id}/cancel"
                        description="Cancela pedido pendente."
                    >
                        <DocCode label="json">{ "order_id": 456, "status": "cancelled" }</DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-pix-refund">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/pix/{order_id}/refund"
                        description="Estorna PIX pago ou em disputa."
                    >
                        <DocCode label="json">{ "order_id": 456, "status": "refunded" }</DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-checkout-sessions">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/checkout/sessions"
                        description="Sessão de checkout hospedado; retorna checkout_url."
                    >
                        <DocTable :columns="fieldColumns" :rows="sessionFields" />
                        <DocCode label="json">
                            { "session_id": "123", "checkout_url": "{{ siteBase }}/api-checkout/xxxxxxxx",
                            "expires_at": "2026-03-09T12:30:00.000000Z" }
                        </DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-payments-card">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/payments/card"
                        description="Pagamento com cartão (payment_token obrigatório)."
                    >
                        <DocCode label="json">{ "order_id": 456, "status": "paid" }</DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-payments-boleto">
                    <DocEndpoint method="POST" path="/api/v1/payments/boleto" description="Gera boleto.">
                        <DocCode label="json">
                            { "order_id": 456, "barcode": "...", "pdf_url": "https://...", "status": "pending" }
                        </DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="idempotencia" title="Idempotência">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Use
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">idempotency_key</code>
                        no body ou header
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Idempotency-Key</code>
                        (máx. 128 caracteres).
                    </p>
                    <DocCallout type="tip" title="Recomendado">Use em todas as criações de pagamento.</DocCallout>
                </DocSection>

                <DocSection id="webhooks-eventos" title="Eventos">
                    <DocTable
                        :columns="[
                            { key: 'event', label: 'Evento' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="webhookEvents"
                    />
                </DocSection>

                <DocSection id="webhooks-formato" title="Formato do payload">
                    <DocCode label="json">
                        { "event": "order.completed", "order_id": 456, "amount": 97.90, "metadata": {} }
                    </DocCode>
                </DocSection>

                <DocSection id="webhooks-assinatura" title="Assinatura do webhook">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Header <strong>X-Webhook-Signature</strong>: HMAC-SHA256 do body bruto com o webhook secret.
                    </p>
                    <DocCallout type="warning" title="Produção">Configure secret e valide a assinatura.</DocCallout>
                </DocSection>

                <DocSection id="webhooks-boas-praticas" title="Boas práticas (webhooks)">
                    <ul class="doc-ul">
                        <li>Responda 2xx rapidamente.</li>
                        <li>Trate eventos duplicados.</li>
                    </ul>
                </DocSection>

                <DocSection id="codigos-de-erro" title="Códigos de erro">
                    <DocTable
                        :columns="[
                            { key: 'code', label: 'Código' },
                            { key: 'meaning', label: 'Significado' },
                        ]"
                        :rows="errorCodes"
                    />
                </DocSection>

                <DocSection id="boas-praticas" title="Boas práticas gerais">
                    <ol class="doc-ol">
                        <li>Idempotency em todas as criações.</li>
                        <li>Webhook com secret validado.</li>
                        <li>HTTPS e chaves só no backend.</li>
                    </ol>
                </DocSection>

                <DocSection id="resumo-endpoints" title="Resumo de endpoints">
                    <DocTable
                        :columns="[
                            { key: 'method', label: 'Método' },
                            { key: 'endpoint', label: 'Endpoint' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="endpointSummary"
                    />
                </DocSection>
            </article>
        </div>
    </div>
</template>

<style scoped>
.api-docs {
    font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif;
}
.doc-h3 {
    margin-top: 3rem;
    margin-bottom: 1rem;
    font-size: 1rem;
    font-weight: 600;
    color: #e4e4e7;
}
.doc-h4 {
    margin-top: 2rem;
    margin-bottom: 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: #d4d4d8;
}
.doc-ul,
.doc-ol {
    margin-block: 1.5rem;
    padding-left: 1.5rem;
    line-height: 1.625;
    color: #a1a1aa;
}
.doc-ul {
    list-style-type: disc;
}
.doc-ol {
    list-style-type: decimal;
}
.doc-ul li + li,
.doc-ol li + li {
    margin-top: 0.5rem;
}
</style>
