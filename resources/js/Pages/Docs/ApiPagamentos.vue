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
    apiKeyScopes,
    balanceResponseFields,
    commonApiErrors,
    customerFields,
    endpointSummary,
    errorCodes,
    fieldColumns,
    integrationPrerequisites,
    integrationSteps,
    navSections,
    orderStatusValues,
    partnerIntegrationSteps,
    partnerRecommendedScopes,
    paymentStatusResponseFields,
    payoutDestinationFields,
    payoutDestinationKeyTypeRules,
    pixAsyncResponseFields,
    pixRequestFields,
    pixResponseFields,
    webhookEvents,
    webhookProvisionExample,
    webhookProvisionFields,
    webhookProvisionResponseExample,
    withdrawalRequestFields,
    withdrawalResponseFields,
    withdrawalWebhookEvents,
    withdrawalWebhookExample,
    webhookPayloadExample,
    webhookVerifyNodeExample,
    webhookVerifyPhpExample,
    whenToUse,
    paymentConfirmationLayers,
    paymentConfirmationChecklist,
    reconciliationPhpExample,
    reconciliationNodeExample,
    idempotentHandlerExample,
} from './apiPagamentosData';
import { ChevronRight, FlaskConical, Menu, X } from 'lucide-vue-next';

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

const pixCurlExample = computed(
    () => `curl -X POST '${apiBase.value}/payments/pix' \\
  -H 'Content-Type: application/json' \\
  -H 'X-Public-Key: gpk_sua_public_key' \\
  -H 'X-Secret-Key: gsk_sua_secret_key' \\
  -H 'Idempotency-Key: pedido-123-pix' \\
  -d '{
    "customer": {
      "email": "cliente@exemplo.com",
      "name": "Cliente Teste",
      "cpf": "52998224725"
    },
    "amount": 97.90,
    "currency": "BRL",
    "partner_checkout_url": "https://loja.exemplo.com/checkout/ped-1001",
    "metadata": { "external_id": "ped-1001" }
  }'`
);

const pixNodeExample = computed(
    () => `const res = await fetch('${apiBase.value}/payments/pix', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Public-Key': process.env.GETFY_PUBLIC_KEY,
    'X-Secret-Key': process.env.GETFY_SECRET_KEY,
    'Idempotency-Key': \`order-\${orderId}-pix\`,
  },
  body: JSON.stringify({
    customer: { email: 'cliente@exemplo.com', name: 'Cliente' },
    amount: 97.90,
    partner_checkout_url: 'https://loja.exemplo.com/checkout/' + orderId,
    metadata: { external_id: orderId },
  }),
});
const data = await res.json();
// data.order_id, data.copy_paste, data.qrcode`
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
                            <li v-for="item in section.items" :key="item.id ?? item.href">
                                <Link
                                    v-if="item.href"
                                    :href="item.href"
                                    class="doc-nav-link flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm font-medium text-zinc-300 transition hover:bg-white/5 hover:text-white"
                                >
                                    {{ item.title }}
                                    <ChevronRight class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </Link>
                                <a
                                    v-else
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
                    <h1 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">API de Pagamentos e Saques</h1>
                    <p class="mt-2 text-base leading-relaxed text-zinc-400">
                        Para <strong class="text-zinc-200">marketplaces, ERPs, SaaS e parceiros</strong>: crie cobranças
                        PIX, acompanhe pagamentos, consulte saldo, solicite saques e receba webhooks em tempo real.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="rounded-full bg-teal-500/20 px-3 py-1 text-xs font-medium text-teal-300">REST</span>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-zinc-400">JSON</span>
                        <Link
                            href="/docs/api-pagamentos/testar"
                            class="inline-flex items-center gap-1.5 rounded-full border border-teal-500/40 bg-teal-500/10 px-3 py-1 text-xs font-medium text-teal-300 hover:bg-teal-500/20"
                        >
                            <FlaskConical class="h-3.5 w-3.5" />
                            Testar API
                            <ChevronRight class="h-3.5 w-3.5 opacity-80" />
                        </Link>
                        <Link
                            href="/docs/api-pagamentos/ia"
                            class="inline-flex items-center gap-1.5 rounded-full border border-violet-500/40 bg-violet-500/10 px-3 py-1 text-xs font-medium text-violet-300 hover:bg-violet-500/20"
                        >
                            <img
                                src="/images/docs/openai-chatgpt.svg"
                                alt=""
                                class="h-3.5 w-3.5 object-contain brightness-0 invert"
                            />
                            <img
                                src="/images/docs/claude-ai.svg"
                                alt=""
                                class="h-3.5 w-3.5 object-contain"
                            />
                            Integrar com IA
                            <ChevronRight class="h-3.5 w-3.5 opacity-80" />
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

                <DocSection id="fluxo-pix" title="Integração em 5 passos">
                    <ol class="doc-ol">
                        <li v-for="(step, i) in integrationSteps" :key="i">{{ step }}</li>
                    </ol>
                    <DocCallout type="tip" title="Webhook vs polling">
                        Prefira webhook <code class="rounded bg-white/10 px-1 py-0.5">order.completed</code> para
                        liberar o produto. Use GET /payments/{order_id} como fallback no servidor — veja
                        <a href="#confirmacao-pagamento-fallbacks" class="text-teal-300 underline hover:text-teal-200">
                            Confirmação de pagamento e fallbacks
                        </a>.
                        Polling no checkout é só para UX; não substitui webhook + job de reconciliação.
                    </DocCallout>
                </DocSection>

                <DocSection id="pre-requisitos" title="Pré-requisitos">
                    <ul class="doc-ul">
                        <li v-for="(item, i) in integrationPrerequisites" :key="i">{{ item }}</li>
                    </ul>
                </DocSection>

                <DocSection id="para-parceiros" title="Integração para parceiros">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Fluxo <strong class="text-zinc-200">recomendado</strong> quando o vendedor conecta as credenciais
                        na sua plataforma — provisione o webhook automaticamente via API.
                    </p>
                    <ol class="doc-ol">
                        <li v-for="(step, i) in partnerIntegrationSteps" :key="i">{{ step }}</li>
                    </ol>
                    <DocCallout type="tip" title="Scopes recomendados para chave de parceiro">
                        <code class="rounded bg-white/10 px-1 py-0.5 text-teal-300">{{ partnerRecommendedScopes }}</code>
                        — ou use a chave principal (acesso total).
                    </DocCallout>
                </DocSection>

                <DocSection id="visao-geral" title="Visão geral">
                    <ul class="doc-ul">
                        <li>QR Code em base64 e código copia e cola na resposta.</li>
                        <li>Consulte status com GET ou use webhooks.</li>
                        <li>
                            Opcionalmente envie
                            <code class="rounded bg-white/10 px-1 py-0.5">partner_checkout_url</code>
                            (HTTPS) com a URL do checkout no seu site — recomendado em produção.
                        </li>
                    </ul>
                </DocSection>

                <DocSection id="quando-usar" title="Quando usar">
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
                        POST /api/v1/payments/pix HTTP/1.1
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
                        <li>
                            Legado: também aceitamos
                            <code class="rounded bg-white/10 px-1 py-0.5">Authorization: Bearer</code>
                            ou header
                            <code class="rounded bg-white/10 px-1 py-0.5">X-API-Key</code>.
                        </li>
                    </ol>
                </DocSection>

                <DocSection id="api-keys-scopes" title="Chaves adicionais e permissões">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        A <strong class="text-zinc-200">chave principal</strong> da conta tem acesso total (pagamentos,
                        saques, consultas). Você pode criar <strong class="text-zinc-200">chaves adicionais</strong> no
                        painel com permissões limitadas (least-privilege).
                    </p>
                    <DocTable
                        :columns="[
                            { key: 'scope', label: 'Permissão' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="apiKeyScopes"
                    />
                    <DocCallout type="tip" title="Compatibilidade">
                        Integrações existentes com a chave principal continuam funcionando sem alteração.
                    </DocCallout>
                </DocSection>

                <DocSection id="seguranca" title="Segurança">
                    <ul class="doc-ul">
                        <li>Nunca exponha a Secret no frontend.</li>
                        <li>Use HTTPS em produção.</li>
                    </ul>
                </DocSection>

                <DocSection id="integracao-conta" title="Chaves e configuração">
                    <p class="text-zinc-400 leading-relaxed">
                        Cada conta tem um par Public + Secret. Configure IPs permitidos no painel. Para webhook, use
                        <code class="rounded bg-white/10 px-1 py-0.5 text-teal-300">PUT /api/v1/webhook</code>
                        (recomendado para parceiros) ou configure manualmente em
                        <code class="rounded bg-white/10 px-1 py-0.5">/aplicacoes-api</code>.
                    </p>
                </DocSection>

                <DocSection id="webhook-provision-api" title="Provisionar webhook (API)">
                    <DocEndpoint
                        method="PUT"
                        path="/api/v1/webhook"
                        description="Registra a URL de recebimento e habilita todos os eventos. Retorna webhook_secret na primeira configuração."
                    >
                        <p class="text-zinc-400 leading-relaxed mb-4">
                            Permissão: <code class="rounded bg-white/10 px-1 py-0.5">webhooks:write</code>.
                            URL deve ser HTTPS. Todos os eventos de pagamento e saque são habilitados automaticamente.
                        </p>
                        <DocTable :columns="fieldColumns" :rows="webhookProvisionFields" />
                        <h4 class="doc-h4">Request</h4>
                        <DocCode label="json">{{ webhookProvisionExample }}</DocCode>
                        <h4 class="doc-h4">Resposta 200 (primeira configuração)</h4>
                        <DocCode label="json">{{ webhookProvisionResponseExample }}</DocCode>
                        <DocCallout type="warning" title="Reconexão">
                            Chamadas repetidas com a mesma URL não devolvem o secret. Use
                            <code class="rounded bg-white/10 px-1 py-0.5">rotate_secret: true</code>
                            ou
                            <code class="rounded bg-white/10 px-1 py-0.5">POST /api/v1/webhook/rotate-secret</code>.
                        </DocCallout>
                    </DocEndpoint>
                    <DocEndpoint
                        method="GET"
                        path="/api/v1/webhook"
                        description="Consulta configuração atual (sem expor o secret)."
                    />
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/webhook/rotate-secret"
                        description="Gera novo webhook_secret (requer URL já configurada)."
                    />
                </DocSection>

                <DocSection id="processadora-webhooks" title="Webhooks (configuração)">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Configure
                        <code class="rounded bg-white/10 px-1 py-0.5 text-teal-300">webhook_url</code>
                        via <code class="rounded bg-white/10 px-1 py-0.5">PUT /api/v1/webhook</code> (recomendado) ou no
                        painel em <code class="rounded bg-white/10 px-1 py-0.5">/aplicacoes-api</code>.
                    </p>
                </DocSection>

                <DocSection id="post-payments-pix">
                    <DocEndpoint
                        method="POST"
                        path="/api/v1/payments/pix"
                        description="Cria cobrança PIX: pedido + QR Code e copia e cola."
                    >
                        <p class="mb-4 text-sm">
                            <Link
                                href="/docs/api-pagamentos/testar?op=post-payments-pix"
                                class="inline-flex items-center gap-1 text-teal-400 hover:underline"
                            >
                                <FlaskConical class="h-3.5 w-3.5" />
                                Testar este endpoint →
                            </Link>
                        </p>
                        <DocCallout type="warning" title="amount vs product_id">
                            Sem <code class="rounded bg-white/10 px-1 py-0.5">product_id</code>, o valor cobrado é o
                            <code class="rounded bg-white/10 px-1 py-0.5">amount</code> enviado. Com
                            <code class="rounded bg-white/10 px-1 py-0.5">product_id</code>, o valor vem do
                            produto, oferta ou plano — o amount do body é <strong class="text-zinc-200">ignorado</strong>.
                        </DocCallout>
                        <DocCallout type="info" title="Ticket mínimo API PIX">
                            O administrador define o valor mínimo em <strong class="text-zinc-200">Plataforma → Financeiro → Limites</strong>.
                            Cobranças abaixo desse valor retornam <strong class="text-zinc-200">422</strong> com mensagem indicando o mínimo
                            (vale para <code class="rounded bg-white/10 px-1 py-0.5">amount</code> livre e para preço efetivo com
                            <code class="rounded bg-white/10 px-1 py-0.5">product_id</code>).
                        </DocCallout>
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocTable :columns="fieldColumns" :rows="pixResponseFields" />
                        <DocCode label="json">
                            {
                              "order_id": 456,
                              "transaction_id": "tx-abc123",
                              "qrcode": "data:image/png;base64,...",
                              "copy_paste": "00020126580014br.gov.bcb.pix...",
                              "status": "pending"
                            }
                        </DocCode>
                        <p class="mt-4 text-sm text-zinc-400">
                            Erro 422: <code class="rounded bg-white/10 px-1 py-0.5">{ "message": "..." }</code> —
                            falha de validação ou indisponibilidade temporária.
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="pix-async" title="Modo assíncrono (opcional)">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Por padrão, <code class="rounded bg-white/10 px-1 py-0.5">POST /payments/pix</code> responde
                        <strong class="text-zinc-200">201</strong> com QR e copia e cola na mesma requisição
                        (comportamento legado, recomendado para a maioria das integrações).
                    </p>
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Para alto volume, envie o header
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Async: true</code>
                        e receba <strong class="text-zinc-200">202</strong> com
                        <code class="rounded bg-white/10 px-1 py-0.5">status: processing</code>. Consulte
                        <code class="rounded bg-white/10 px-1 py-0.5">GET /payments/{order_id}</code> ou aguarde o
                        webhook <code class="rounded bg-white/10 px-1 py-0.5">pix.generated</code>.
                    </p>
                    <DocTable :columns="fieldColumns" :rows="pixAsyncResponseFields" />
                </DocSection>

                <DocSection id="post-payments-pix-campos" title="Campos do request (PIX)">
                    <DocTable :columns="fieldColumns" :rows="pixRequestFields" />
                </DocSection>

                <DocSection id="exemplo-curl" title="Exemplo curl (criar PIX)">
                    <DocCode label="bash">{{ pixCurlExample }}</DocCode>
                    <h4 class="doc-h4">Node.js (fetch)</h4>
                    <DocCode label="javascript">{{ pixNodeExample }}</DocCode>
                </DocSection>

                <DocSection id="get-payments-list">
                    <DocEndpoint method="GET" path="/api/v1/payments" description="Lista pedidos da integração autenticada.">
                        <p class="mb-4 text-sm text-zinc-400">
                            Query opcional: <code class="rounded bg-white/10 px-1 py-0.5">status</code>,
                            <code class="rounded bg-white/10 px-1 py-0.5">per_page</code> (máx. 100).
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="get-payments-order-id">
                    <DocEndpoint method="GET" path="/api/v1/payments/{order_id}" description="Consulta status do pedido.">
                        <p class="mb-4 text-sm text-zinc-400">
                            Só retorna pedidos criados pela mesma aplicação (mesmo par de chaves). Pedidos de outra
                            integração retornam 404.
                        </p>
                        <DocTable :columns="fieldColumns" :rows="paymentStatusResponseFields" />
                        <DocCode label="json">
                            {
                              "order_id": 456,
                              "status": "completed",
                              "amount": 97.90,
                              "email": "cliente@exemplo.com",
                              "transaction_id": "tx-abc",
                              "metadata": { "external_id": "ped-1001" },
                              "created_at": "2026-06-13T14:00:00.000000Z",
                              "updated_at": "2026-06-13T14:05:12.000000Z"
                            }
                        </DocCode>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="status-pedido" title="Status do pedido">
                    <DocTable
                        :columns="[
                            { key: 'status', label: 'Status' },
                            { key: 'quando', label: 'Quando ocorre' },
                        ]"
                        :rows="orderStatusValues"
                    />
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

                <DocSection id="get-balance">
                    <DocEndpoint method="GET" path="/api/v1/balance" description="Saldo disponível por carteira (PIX, cartão, boleto).">
                        <DocTable :columns="fieldColumns" :rows="balanceResponseFields" />
                        <DocCallout type="tip" title="Liquidação">
                            Valores em <code class="rounded bg-white/10 px-1 py-0.5">pending_balance</code> ainda não
                            estão disponíveis para saque.
                        </DocCallout>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="put-payout-destination">
                    <DocEndpoint
                        method="PUT"
                        path="/api/v1/payout-destination"
                        description="Valida uma chave PIX de destino. Não altera a chave master do infoprodutor."
                    >
                        <DocTable :columns="fieldColumns" :rows="payoutDestinationFields" />
                        <h4 class="doc-h4">Regras por tipo de chave</h4>
                        <DocTable
                            :columns="[
                                { key: 'pix_key_type', label: 'pix_key_type' },
                                { key: 'key_owner_document', label: 'key_owner_document' },
                            ]"
                            :rows="payoutDestinationKeyTypeRules"
                        />
                        <DocCode label="json">
                            {
                              "pix_key": "cliente@exemplo.com",
                              "pix_key_type": "email",
                              "key_owner_document": "52998224725"
                            }
                        </DocCode>
                        <DocCallout type="warning" title="Chave master preservada">
                            Este endpoint apenas valida o destino. A chave master do painel Financeiro do
                            infoprodutor <strong class="text-zinc-200">não é alterada</strong>. Envie
                            <code class="rounded bg-white/10 px-1 py-0.5">pix_key</code> em cada
                            <code class="rounded bg-white/10 px-1 py-0.5">POST /withdrawals</code>.
                        </DocCallout>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-withdrawals">
                    <DocEndpoint method="POST" path="/api/v1/withdrawals" description="Solicita saque do saldo disponível para a chave informada.">
                        <p class="mb-4 text-sm">
                            <Link
                                href="/docs/api-pagamentos/testar?op=post-withdrawals"
                                class="inline-flex items-center gap-1 text-teal-400 hover:underline"
                            >
                                <FlaskConical class="h-3.5 w-3.5" />
                                Testar este endpoint →
                            </Link>
                        </p>
                        <DocTable :columns="fieldColumns" :rows="withdrawalRequestFields" />
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocTable :columns="fieldColumns" :rows="withdrawalResponseFields" />
                        <DocCallout type="tip" title="Destino por saque">
                            Informe <code class="rounded bg-white/10 px-1 py-0.5">pix_key</code>,
                            <code class="rounded bg-white/10 px-1 py-0.5">pix_key_type</code> e
                            <code class="rounded bg-white/10 px-1 py-0.5">key_owner_document</code> (quando exigido)
                            neste request. A chave fica gravada na transação do saque e
                            <strong class="text-zinc-200">não sobrescreve</strong> a chave master do infoprodutor.
                        </DocCallout>
                        <DocCallout type="warning" title="Idempotência">
                            Use <code class="rounded bg-white/10 px-1 py-0.5">Idempotency-Key</code> para evitar saques
                            duplicados em caso de retry de rede.
                        </DocCallout>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="get-withdrawals">
                    <DocEndpoint method="GET" path="/api/v1/withdrawals" description="Lista saques da conta.">
                        <p class="mb-4 text-sm text-zinc-400">
                            Query opcional: <code class="rounded bg-white/10 px-1 py-0.5">status</code>,
                            <code class="rounded bg-white/10 px-1 py-0.5">per_page</code>.
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="webhooks-saque" title="Webhooks de saque">
                    <DocTable
                        :columns="[
                            { key: 'event', label: 'Evento' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="withdrawalWebhookEvents"
                    />
                    <h4 class="doc-h4">Exemplo withdrawal.completed</h4>
                    <DocCode label="json">{{ withdrawalWebhookExample }}</DocCode>
                </DocSection>

                <DocSection id="idempotencia-pix" title="Idempotência">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Use
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">idempotency_key</code>
                        no body ou header
                        <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Idempotency-Key</code>
                        (máx. 128 caracteres). A mesma chave devolve a resposta original sem duplicar a operação.
                    </p>
                    <DocCallout type="tip" title="Chave principal">
                        Na chave principal, idempotência é opcional (recomendada). Chaves adicionais podem exigir
                        idempotência obrigatória.
                    </DocCallout>
                </DocSection>

                <DocSection id="webhooks-eventos" title="Eventos de pagamento">
                    <DocTable
                        :columns="[
                            { key: 'event', label: 'Evento' },
                            { key: 'desc', label: 'Descrição' },
                        ]"
                        :rows="webhookEvents"
                    />
                </DocSection>

                <DocSection id="webhooks-formato" title="Formato do payload">
                    <p class="mb-4 text-sm text-zinc-400">
                        POST JSON na <code class="rounded bg-white/10 px-1 py-0.5">webhook_url</code> da aplicação.
                        Campo <code class="rounded bg-white/10 px-1 py-0.5">customer</code> incluído quando há dados
                        do comprador no pedido.
                    </p>
                    <DocCode label="json">{{ webhookPayloadExample }}</DocCode>
                </DocSection>

                <DocSection id="webhooks-assinatura" title="Assinatura e entrega">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Headers enviados em cada webhook:
                    </p>
                    <ul class="doc-ul mb-4">
                        <li><code class="rounded bg-white/10 px-1 py-0.5">X-Webhook-Signature</code> — HMAC-SHA256 do body bruto</li>
                        <li><code class="rounded bg-white/10 px-1 py-0.5">X-Webhook-Id</code> — ID único do evento (use para deduplicar)</li>
                        <li><code class="rounded bg-white/10 px-1 py-0.5">X-Webhook-Timestamp</code> — Unix timestamp do envio</li>
                    </ul>
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Em caso de falha na entrega (seu endpoint offline ou não-2xx), reenviamos automaticamente com
                        backoff exponencial. Responda <strong class="text-zinc-200">HTTP 2xx</strong> rapidamente.
                    </p>
                    <h4 class="doc-h4">PHP</h4>
                    <DocCode label="php">{{ webhookVerifyPhpExample }}</DocCode>
                    <h4 class="doc-h4">Node.js</h4>
                    <DocCode label="javascript">{{ webhookVerifyNodeExample }}</DocCode>
                    <DocCallout type="warning" title="Produção">Configure secret e valide a assinatura.</DocCallout>
                </DocSection>

                <DocSection id="webhooks-boas-praticas" title="Boas práticas (webhooks)">
                    <ul class="doc-ul">
                        <li>Responda HTTP 2xx em até alguns segundos; processe assincronamente se necessário.</li>
                        <li>Deduplique por <code class="rounded bg-white/10 px-1 py-0.5">event_id</code> (cada entrega tem ID único).</li>
                        <li>Use <code class="rounded bg-white/10 px-1 py-0.5">metadata</code> para correlacionar com seu pedido interno.</li>
                        <li>Trate <code class="rounded bg-white/10 px-1 py-0.5">order.completed</code> como confirmação de pagamento.</li>
                    </ul>
                </DocSection>

                <DocSection id="confirmacao-pagamento-fallbacks" title="Confirmação de pagamento e fallbacks">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Não confie em um único canal para saber se o PIX foi pago. Implemente
                        <strong class="text-zinc-200">três camadas</strong> complementares: webhook assinado (primário),
                        reconciliação em background no servidor (fallback obrigatório) e polling opcional na UI (só UX).
                    </p>
                    <DocTable
                        :columns="[
                            { key: 'layer', label: 'Camada' },
                            { key: 'where', label: 'Onde roda' },
                            { key: 'role', label: 'Função' },
                        ]"
                        :rows="paymentConfirmationLayers"
                    />
                    <h3 class="doc-h3">Fluxo recomendado</h3>
                    <DocCode label="text">POST /payments/pix → salvar order_id no pedido interno
       ↓
Webhook order.completed (HMAC) → marcar pago + liberar produto (idempotente)
       ↓ (se falhar / atrasar)
Job reconciliação (1–2 min) → GET /payments → mesmo handler idempotente
       ↓ (paralelo, só UX)
Polling no checkout (opcional) → atualiza tela</DocCode>
                    <DocCallout type="warning" title="Nunca libere só com polling do browser">
                        O polling na tela do QR não cobre o caso em que o comprador paga e fecha a aba. O job de
                        reconciliação no servidor é <strong>obrigatório</strong> em produção, mesmo com webhook
                        configurado.
                    </DocCallout>
                    <h3 class="doc-h3">Reconciliação em background</h3>
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Consulte a API a cada <strong class="text-zinc-200">60–120 segundos</strong> por
                        <strong class="text-zinc-200">6–12 horas</strong> após criar a cobrança:
                    </p>
                    <ul class="doc-ul">
                        <li>
                            Individual:
                            <code class="rounded bg-white/10 px-1 py-0.5">GET /api/v1/payments/{order_id}</code>
                        </li>
                        <li>
                            Em lote:
                            <code class="rounded bg-white/10 px-1 py-0.5">GET /api/v1/payments?status=pending&amp;per_page=100</code>
                        </li>
                        <li>Se <code class="rounded bg-white/10 px-1 py-0.5">status === "completed"</code>, execute o mesmo pipeline do webhook.</li>
                    </ul>
                    <h4 class="doc-h4">PHP (scheduler)</h4>
                    <DocCode label="php">{{ reconciliationPhpExample }}</DocCode>
                    <h4 class="doc-h4">Node.js (cron)</h4>
                    <DocCode label="javascript">{{ reconciliationNodeExample }}</DocCode>
                    <h4 class="doc-h4">Handler idempotente (compartilhado)</h4>
                    <DocCode label="php">{{ idempotentHandlerExample }}</DocCode>
                    <h3 class="doc-h3">Checklist de segurança</h3>
                    <ul class="doc-ul">
                        <li v-for="(item, i) in paymentConfirmationChecklist" :key="i">{{ item }}</li>
                    </ul>
                    <p class="mt-4 text-sm text-zinc-500">
                        <Link href="/docs/api-pagamentos/testar" class="text-teal-400 hover:underline">Testar API</Link>
                        ·
                        <Link href="/docs/api-pagamentos/ia" class="text-teal-400 hover:underline">Integrar com IA</Link>
                    </p>
                </DocSection>

                <DocSection id="erros-comuns" title="Erros comuns e troubleshooting">
                    <DocTable
                        :columns="[
                            { key: 'code', label: 'HTTP' },
                            { key: 'message', label: 'Mensagem' },
                            { key: 'cause', label: 'Causa provável' },
                            { key: 'action', label: 'O que fazer' },
                        ]"
                        :rows="commonApiErrors"
                    />
                    <h3 class="doc-h3">PIX não gera QR / copy_paste</h3>
                    <ul class="doc-ul">
                        <li>API PIX desabilitada para a conta</li>
                        <li>Conta aguardando aprovação ou verificação</li>
                        <li><code class="rounded bg-white/10 px-1 py-0.5">amount</code> menor que 0.01 ou produto indisponível</li>
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
