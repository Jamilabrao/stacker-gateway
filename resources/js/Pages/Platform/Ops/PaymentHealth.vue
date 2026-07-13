<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import {
    AlertTriangle,
    CheckCircle2,
    Copy,
    ExternalLink,
    Info,
    Loader2,
    RefreshCw,
    Search,
    ShieldCheck,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
});

const dashboard = ref({ ...props.dashboard });
const loading = ref(false);
const batchReconciling = ref(false);
const orderLoading = ref({});
const orderProbes = ref({});
const showBatchConfirm = ref(false);
const confirmOrderId = ref(null);
const flashMessage = ref(null);
const flashType = ref('info');

const infrastructure = computed(() => dashboard.value.infrastructure ?? {});
const webhooks = computed(() => dashboard.value.webhooks ?? {});
const telemetry = computed(() => dashboard.value.telemetry ?? {});
const pendingSummary = computed(() => dashboard.value.pending_summary ?? {});
const pendingOrders = computed(() => dashboard.value.pending_orders ?? []);
const issues = computed(() => dashboard.value.issues ?? []);

const severityStyles = {
    critical: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100',
    info: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-100',
};

function formatDateTime(iso) {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat('pt-BR', {
            dateStyle: 'short',
            timeStyle: 'medium',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function formatMinutesAgo(minutes) {
    if (minutes === null || minutes === undefined) {
        return 'Nunca registrado';
    }
    if (minutes < 1) {
        return 'Agora há pouco';
    }
    if (minutes < 60) {
        return `Há ${minutes} min`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return `Há ${hours}h`;
    }
    const days = Math.floor(hours / 24);
    return `Há ${days} dia(s)`;
}

function formatMoney(value) {
    const amount = Number(value ?? 0);
    try {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: 'BRL',
        }).format(amount);
    } catch {
        return amount.toFixed(2);
    }
}

function gatewayLabel(slug) {
    if (slug === 'mercadopago') {
        return 'Mercado Pago';
    }
    if (slug === 'cajupay') {
        return 'CajuPay';
    }
    return slug;
}

function statusBadgeClass(healthy) {
    return healthy
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
        : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200';
}

function probeStatusClass(status) {
    if (status === 'paid') {
        return 'text-emerald-600 dark:text-emerald-400';
    }
    if (status === 'not_pending' || status === 'unsupported') {
        return 'text-zinc-500';
    }
    return 'text-amber-600 dark:text-amber-400';
}

function setFlash(message, type = 'info') {
    flashMessage.value = message;
    flashType.value = type;
}

function refreshDashboard() {
    router.get('/plataforma/ops/saude-pagamentos', {}, {
        preserveScroll: true,
        preserveState: false,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        setFlash('URL copiada.', 'success');
    } catch {
        setFlash('Não foi possível copiar.', 'error');
    }
}

async function runBatchReconcile() {
    showBatchConfirm.value = false;
    batchReconciling.value = true;
    setFlash(null);

    try {
        const { data } = await window.axios.post('/plataforma/ops/saude-pagamentos/reconciliar', {
            limit: 50,
        });
        if (data.dashboard) {
            dashboard.value = data.dashboard;
        }
        setFlash(data.message ?? 'Reconciliação concluída.', data.result?.paid > 0 ? 'success' : 'info');
    } catch (err) {
        const msg = err.response?.data?.message ?? 'Erro ao executar reconciliação.';
        setFlash(msg, 'error');
    } finally {
        batchReconciling.value = false;
    }
}

async function probeOrder(orderId) {
    orderLoading.value = { ...orderLoading.value, [orderId]: 'probe' };
    try {
        const { data } = await window.axios.get(`/plataforma/ops/saude-pagamentos/pedidos/${orderId}/probe`);
        orderProbes.value = { ...orderProbes.value, [orderId]: data.probe };
    } catch (err) {
        orderProbes.value = {
            ...orderProbes.value,
            [orderId]: { status: 'error', message: err.response?.data?.message ?? 'Erro ao consultar.' },
        };
    } finally {
        const next = { ...orderLoading.value };
        delete next[orderId];
        orderLoading.value = next;
    }
}

async function reconcileOrder(orderId) {
    confirmOrderId.value = null;
    orderLoading.value = { ...orderLoading.value, [orderId]: 'reconcile' };

    try {
        const { data } = await window.axios.post(`/plataforma/ops/saude-pagamentos/pedidos/${orderId}/reconciliar`);
        if (data.dashboard) {
            dashboard.value = data.dashboard;
        }
        if (data.probe) {
            orderProbes.value = { ...orderProbes.value, [orderId]: data.probe };
        }
        setFlash(data.message ?? 'Pedido processado.', data.success ? 'success' : 'error');
    } catch (err) {
        const data = err.response?.data;
        if (data?.probe) {
            orderProbes.value = { ...orderProbes.value, [orderId]: data.probe };
        }
        setFlash(data?.message ?? 'Não foi possível reconciliar o pedido.', 'error');
    } finally {
        const next = { ...orderLoading.value };
        delete next[orderId];
        orderLoading.value = next;
    }
}

function isOrderBusy(orderId) {
    return Boolean(orderLoading.value[orderId]);
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    Operações
                </p>
                <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">
                    Saúde de Pagamentos
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Diagnóstico de webhooks, filas, reconciliação automática e pedidos pendentes (Mercado Pago e CajuPay).
                    Ações de correção só confirmam pagamentos verificados na API do gateway.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" class="gap-2" :disabled="loading" @click="refreshDashboard">
                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" />
                    Atualizar
                </Button>
                <Button
                    type="button"
                    class="gap-2"
                    :disabled="batchReconciling"
                    @click="showBatchConfirm = true"
                >
                    <Loader2 v-if="batchReconciling" class="h-4 w-4 animate-spin" />
                    <ShieldCheck v-else class="h-4 w-4" />
                    Reconciliar agora
                </Button>
            </div>
        </div>

        <div
            v-if="flashMessage"
            class="rounded-xl border px-4 py-3 text-sm"
            :class="flashType === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100'
                : flashType === 'error'
                    ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100'
                    : 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-100'"
        >
            {{ flashMessage }}
        </div>

        <div v-if="issues.length" class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Diagnóstico
            </h2>
            <div
                v-for="(issue, idx) in issues"
                :key="idx"
                class="rounded-xl border p-4"
                :class="severityStyles[issue.severity] ?? severityStyles.info"
            >
                <div class="flex gap-3">
                    <AlertTriangle v-if="issue.severity === 'critical'" class="mt-0.5 h-5 w-5 shrink-0" />
                    <Info v-else-if="issue.severity === 'info'" class="mt-0.5 h-5 w-5 shrink-0" />
                    <AlertTriangle v-else class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-medium">{{ issue.message }}</p>
                        <p v-if="issue.action" class="mt-1 text-sm opacity-90">
                            {{ issue.action }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase text-zinc-500">Scheduler</p>
                <div class="mt-2 flex items-center gap-2">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(infrastructure.schedule_healthy)">
                        {{ infrastructure.schedule_healthy ? 'Ativo' : 'Parado?' }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ formatDateTime(infrastructure.schedule_heartbeat) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase text-zinc-500">Workers</p>
                <div class="mt-2 flex items-center gap-2">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(infrastructure.queue_healthy)">
                        {{ infrastructure.queue_healthy ? 'Ativo' : 'Parado?' }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ formatDateTime(infrastructure.queue_heartbeat) }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase text-zinc-500">Reconciliação</p>
                <div class="mt-2 flex items-center gap-2">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadgeClass(infrastructure.reconcile_healthy)">
                        {{ infrastructure.reconcile_healthy ? 'Ativa' : 'Parada?' }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-zinc-500">
                    {{ formatDateTime(infrastructure.reconcile_heartbeat) }}
                </p>
                <p
                    v-if="infrastructure.reconcile_last_stats"
                    class="mt-1 text-xs text-zinc-500"
                >
                    Último: {{ infrastructure.reconcile_last_stats.checked ?? 0 }} checados ·
                    {{ infrastructure.reconcile_last_stats.paid ?? 0 }} pagos
                    <template v-if="(infrastructure.reconcile_last_stats.skipped_credential ?? 0) > 0">
                        · {{ infrastructure.reconcile_last_stats.skipped_credential }} sem credencial
                    </template>
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase text-zinc-500">Fila webhooks-inbound</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ infrastructure.queues?.['webhooks-inbound']?.depth ?? '—' }}
                </p>
                <p class="mt-1 text-xs text-zinc-500">
                    {{ infrastructure.queue_connection }}
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-xs font-medium uppercase text-zinc-500">Pendentes (45d)</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                    {{ pendingSummary.total ?? 0 }}
                </p>
                <p class="mt-1 text-xs text-zinc-500">
                    MP: {{ pendingSummary.by_gateway?.mercadopago ?? 0 }} · CajuPay: {{ pendingSummary.by_gateway?.cajupay ?? 0 }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold text-zinc-900 dark:text-white">
                URLs de webhook
            </h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Cadastre estas URLs no painel do Mercado Pago e configure o webhook CajuPay com a URL pública correta.
            </p>
            <div class="mt-4 space-y-3">
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                    <p class="text-xs font-medium text-zinc-500">APP_URL</p>
                    <p class="mt-1 break-all text-sm">{{ webhooks.app_url }}</p>
                </div>
                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
                    <p class="text-xs font-medium text-zinc-500">GETFY_WEBHOOK_PUBLIC_URL</p>
                    <p class="mt-1 break-all text-sm">{{ webhooks.webhook_public_url ?? '(não definido — usa APP_URL)' }}</p>
                </div>
                <div
                    v-for="(config, slug) in webhooks.gateways"
                    :key="slug"
                    class="flex flex-wrap items-start justify-between gap-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase text-zinc-500">{{ gatewayLabel(slug) }}</p>
                        <p class="mt-1 break-all text-sm">{{ config.url }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span
                                class="inline-flex rounded-full px-2 py-0.5 text-xs"
                                :class="config.is_https ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
                            >
                                {{ config.is_https ? 'HTTPS' : 'Sem HTTPS' }}
                            </span>
                            <span
                                v-if="config.is_localhost"
                                class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800"
                            >
                                Localhost
                            </span>
                        </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" class="gap-1" @click="copyText(config.url)">
                        <Copy class="h-3.5 w-3.5" />
                        Copiar
                    </Button>
                </div>
            </div>
            <Link
                href="/plataforma/financeiro"
                class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-[var(--color-primary)]"
            >
                Ir para Financeiro
                <ExternalLink class="h-3.5 w-3.5" />
            </Link>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold text-zinc-900 dark:text-white">
                Últimos webhooks recebidos
            </h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div
                    v-for="(item, slug) in telemetry.gateways"
                    :key="slug"
                    class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                >
                    <p class="text-sm font-medium">{{ gatewayLabel(slug) }}</p>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ formatDateTime(item.last_received_at) }}
                    </p>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ formatMinutesAgo(item.minutes_ago) }}
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                <h2 class="font-semibold text-zinc-900 dark:text-white">
                    Pedidos pendentes
                </h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Consulte o status na API do gateway antes de reconciliar. Só pedidos confirmados como pagos serão concluídos.
                </p>
            </div>

            <div v-if="pendingOrders.length === 0" class="p-8 text-center text-sm text-zinc-500">
                Nenhum pedido pendente monitorado nos últimos 45 dias.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3">Pedido</th>
                            <th class="px-4 py-3">Gateway</th>
                            <th class="px-4 py-3">Valor</th>
                            <th class="px-4 py-3">Idade</th>
                            <th class="px-4 py-3">Sinais</th>
                            <th class="px-4 py-3">Probe</th>
                            <th class="px-4 py-3 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="order in pendingOrders" :key="order.id">
                            <td class="px-4 py-3">
                                <div class="font-medium">#{{ order.id }}</div>
                                <div class="text-xs text-zinc-500">
                                    tenant {{ order.tenant_id ?? '—' }}
                                </div>
                                <div v-if="order.gateway_id" class="mt-0.5 truncate text-xs text-zinc-400" :title="order.gateway_id">
                                    {{ order.gateway_id }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ gatewayLabel(order.gateway) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ formatMoney(order.amount) }}
                            </td>
                            <td class="px-4 py-3 text-zinc-500">
                                {{ order.age_minutes != null ? `${order.age_minutes} min` : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-if="!order.has_gateway_id"
                                        class="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800"
                                    >
                                        sem gateway_id
                                    </span>
                                    <span
                                        v-if="!order.has_credentials"
                                        class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800"
                                    >
                                        sem credencial
                                    </span>
                                    <span
                                        v-if="order.reconcile_window_expired"
                                        class="rounded bg-zinc-200 px-1.5 py-0.5 text-xs text-zinc-700"
                                    >
                                        janela expirada
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="orderProbes[order.id]" class="max-w-[200px]">
                                    <span class="font-medium" :class="probeStatusClass(orderProbes[order.id].gateway_api_status ?? orderProbes[order.id].status)">
                                        {{ orderProbes[order.id].gateway_api_status ?? orderProbes[order.id].status ?? '—' }}
                                    </span>
                                    <p class="mt-0.5 text-xs text-zinc-500 line-clamp-2">
                                        {{ orderProbes[order.id].message }}
                                    </p>
                                </div>
                                <span v-else class="text-xs text-zinc-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="gap-1"
                                        :disabled="isOrderBusy(order.id)"
                                        @click="probeOrder(order.id)"
                                    >
                                        <Loader2 v-if="orderLoading[order.id] === 'probe'" class="h-3.5 w-3.5 animate-spin" />
                                        <Search v-else class="h-3.5 w-3.5" />
                                        Probe
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        class="gap-1"
                                        :disabled="isOrderBusy(order.id)"
                                        @click="confirmOrderId = order.id"
                                    >
                                        <Loader2 v-if="orderLoading[order.id] === 'reconcile'" class="h-3.5 w-3.5 animate-spin" />
                                        <CheckCircle2 v-else class="h-3.5 w-3.5" />
                                        Reconciliar
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-zinc-500">
            Gerado em {{ formatDateTime(dashboard.generated_at) }}.
            Reconciliação automática: a cada 1–2 min via scheduler.
            <span v-if="infrastructure.cron_secret_configured">Cron URL configurado.</span>
        </p>

        <div
            v-if="showBatchConfirm"
            class="fixed inset-0 z-[100000] flex items-center justify-center bg-black/50 p-4"
            @click.self="showBatchConfirm = false"
        >
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Executar reconciliação?
                </h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Serão consultados até 50 pedidos pendentes no Mercado Pago e CajuPay.
                    Apenas pedidos confirmados como pagos na API do gateway serão marcados como concluídos.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="showBatchConfirm = false">
                        Cancelar
                    </Button>
                    <Button type="button" :disabled="batchReconciling" @click="runBatchReconcile">
                        Confirmar
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="confirmOrderId"
            class="fixed inset-0 z-[100000] flex items-center justify-center bg-black/50 p-4"
            @click.self="confirmOrderId = null"
        >
            <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Reconciliar pedido #{{ confirmOrderId }}?
                </h3>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    O sistema consultará a API do gateway. O pedido só será concluído se o pagamento estiver confirmado como pago.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <Button type="button" variant="outline" @click="confirmOrderId = null">
                        Cancelar
                    </Button>
                    <Button type="button" :disabled="isOrderBusy(confirmOrderId)" @click="reconcileOrder(confirmOrderId)">
                        Confirmar
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
