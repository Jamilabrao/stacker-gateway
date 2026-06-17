<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { RefreshCw, Wallet, AlertTriangle, ExternalLink } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    status: {
        type: String,
        default: 'ok',
    },
    balance: {
        type: Object,
        default: null,
    },
    error: {
        type: String,
        default: null,
    },
    gateway_connected: {
        type: Boolean,
        default: false,
    },
});

const isOk = computed(() => props.status === 'ok' && props.balance);
const isMissingCredentials = computed(() => props.status === 'missing_credentials');
const isError = computed(() => props.status === 'error');

function formatMoney(value, currency = 'BRL') {
    const amount = Number(value ?? 0);
    try {
        return new Intl.NumberFormat('pt-BR', {
            style: 'currency',
            currency: currency || 'BRL',
        }).format(amount);
    } catch {
        return amount.toFixed(2);
    }
}

function formatFetchedAt(iso) {
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

function refresh() {
    router.get('/plataforma/ops/mercadopago-saldo', { refresh: 1 }, {
        preserveScroll: true,
        preserveState: false,
    });
}
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    Ferramenta interna
                </p>
                <h1 class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">
                    Saldo Mercado Pago
                </h1>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Saldo da conta Mercado Pago configurada em Financeiro → Adquirentes. Não confundir com a carteira Getfy em Saldo.
                </p>
            </div>
            <Button type="button" variant="outline" class="gap-2" @click="refresh">
                <RefreshCw class="h-4 w-4" />
                Atualizar
            </Button>
        </div>

        <div
            v-if="isMissingCredentials"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900/50 dark:bg-amber-950/30"
        >
            <div class="flex gap-3">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div>
                    <h2 class="font-semibold text-amber-900 dark:text-amber-100">
                        Credencial Mercado Pago não configurada
                    </h2>
                    <p class="mt-2 text-sm text-amber-800 dark:text-amber-200/90">
                        Configure o Access Token global em Financeiro → Adquirentes → Mercado Pago.
                    </p>
                    <Link
                        href="/plataforma/financeiro"
                        class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-amber-900 underline dark:text-amber-100"
                    >
                        Ir para Financeiro
                        <ExternalLink class="h-3.5 w-3.5" />
                    </Link>
                </div>
            </div>
        </div>

        <div
            v-else-if="isError"
            class="rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/30"
        >
            <div class="flex gap-3">
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />
                <div>
                    <h2 class="font-semibold text-red-900 dark:text-red-100">
                        Não foi possível consultar o saldo
                    </h2>
                    <p class="mt-2 text-sm text-red-800 dark:text-red-200/90">
                        {{ error }}
                    </p>
                    <Link
                        href="/plataforma/financeiro"
                        class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-red-900 underline dark:text-red-100"
                    >
                        Revisar credenciais Mercado Pago
                        <ExternalLink class="h-3.5 w-3.5" />
                    </Link>
                </div>
            </div>
        </div>

        <template v-else-if="isOk">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="balance.is_sandbox
                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'"
                >
                    {{ balance.is_sandbox ? 'Sandbox' : 'Produção' }}
                </span>
                <span
                    v-if="gateway_connected"
                    class="inline-flex rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                >
                    Gateway conectado
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Total
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatMoney(balance.total_amount, balance.currency_id) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Disponível
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                        {{ formatMoney(balance.available_balance, balance.currency_id) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Indisponível
                    </p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-700 dark:text-zinc-300">
                        {{ formatMoney(balance.unavailable_balance, balance.currency_id) }}
                    </p>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-start gap-3">
                    <Wallet class="mt-0.5 h-5 w-5 shrink-0 text-zinc-400" />
                    <div class="min-w-0 space-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                        <p v-if="balance.email">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">Conta:</span>
                            {{ balance.email }}
                            <span v-if="balance.nickname" class="text-zinc-500">({{ balance.nickname }})</span>
                        </p>
                        <p>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">User ID:</span>
                            {{ balance.user_id }}
                        </p>
                        <p>
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">Consultado em:</span>
                            {{ formatFetchedAt(balance.fetched_at) }}
                        </p>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
