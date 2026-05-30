<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import GatewayCard from '@/components/settings/GatewayCard.vue';
import GatewayConfigSidebar from '@/components/settings/GatewayConfigSidebar.vue';
import {
    Banknote,
    Barcode,
    CalendarClock,
    CreditCard,
    LayoutGrid,
    Percent,
    QrCode,
    Repeat,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import FeeFixedInput from '@/components/ui/FeeFixedInput.vue';
import FeePercentInput from '@/components/ui/FeePercentInput.vue';
import {
    formatPercentForInput,
    normalizeMerchantFeeRulesForSubmit,
} from '@/lib/percentDecimal';

const feeMethodRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'api_pix', label: 'API — PIX' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' },
    { key: 'boleto', label: 'Boleto' },
    { key: 'withdrawal', label: 'Saque' },
];

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    gateways: {
        type: Array,
        default: () => [],
    },
    gateway_order: {
        type: Object,
        default: () => ({ pix: [], card: [], boleto: [], pix_auto: [] }),
    },
    merchant_fee_rules: {
        type: Object,
        default: () => ({}),
    },
    merchant_settlement_rules: {
        type: Object,
        default: () => ({}),
    },
    api_pix_enabled: { type: Boolean, default: true },
    /** @type {'auto'|'cajupay'|'spacepag'|'woovi'|'onlyup'} */
    payout_gateway_preference: { type: String, default: 'auto' },
    /** Slug efetivo usado hoje (pode diferir do preferido se este não estiver conectado). */
    payout_gateway_active: { type: String, default: null },
    gateway_webhook_security_warnings: { type: Array, default: () => [] },
    platform_payment_methods_enabled: {
        type: Object,
        default: () => ({
            pix: true,
            card: true,
            boleto: true,
            pix_auto: true,
            apple_pay: true,
            google_pay: true,
        }),
    },
    platform_payment_method_labels: { type: Array, default: () => [] },
});

const GATEWAYS_API_BASE = '/plataforma/financeiro/gateways';

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        : '';
}

/** Adquirente principal por método (primeiro da ordem de redundância). */
const primaryPix = ref('');
const primaryCard = ref('');
const primaryBoleto = ref('');
const primaryPixAuto = ref('');
const savingAcquirerOrder = ref(false);
const acquirerOrderMessage = ref(null);
const acquirerOrderError = ref(false);

function connectedGatewaysForMethod(method) {
    return (props.gateways || []).filter(
        (g) => g.is_connected && Array.isArray(g.methods) && g.methods.includes(method)
    );
}

function syncPrimaryFromProps() {
    const o = props.gateway_order || {};
    const pick = (method) => {
        const list = o[method] || [];
        const first = list[0];
        const conn = connectedGatewaysForMethod(method).map((g) => g.slug);
        if (first && conn.includes(first)) return first;
        return conn[0] || '';
    };
    primaryPix.value = pick('pix');
    primaryCard.value = pick('card');
    primaryBoleto.value = pick('boleto');
    primaryPixAuto.value = pick('pix_auto');
}

watch(
    () => [props.gateway_order, props.gateways],
    () => syncPrimaryFromProps(),
    { deep: true, immediate: true }
);

/**
 * Monta a lista completa: principal primeiro, depois os demais conectados (redundância).
 * @param {string} method
 * @param {string} primarySlug
 */
function buildGatewayOrderList(method, primarySlug) {
    const prev = (props.gateway_order && props.gateway_order[method]) || [];
    const connected = connectedGatewaysForMethod(method).map((g) => g.slug);
    if (connected.length === 0) {
        return Array.isArray(prev) ? [...prev] : [];
    }
    if (!primarySlug || !connected.includes(primarySlug)) {
        const filtered = prev.filter((s) => connected.includes(s));
        return filtered.length ? filtered : [...connected];
    }
    const rest = [];
    const seen = new Set([primarySlug]);
    for (const s of prev) {
        if (!seen.has(s) && connected.includes(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    for (const s of connected) {
        if (!seen.has(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    return [primarySlug, ...rest];
}

async function saveAcquirerOrder() {
    savingAcquirerOrder.value = true;
    acquirerOrderMessage.value = null;
    acquirerOrderError.value = false;
    try {
        const gateway_order = {
            pix: buildGatewayOrderList('pix', primaryPix.value),
            card: buildGatewayOrderList('card', primaryCard.value),
            boleto: buildGatewayOrderList('boleto', primaryBoleto.value),
            pix_auto: buildGatewayOrderList('pix_auto', primaryPixAuto.value),
        };
        await axios.put(
            `${GATEWAYS_API_BASE.replace(/\/$/, '')}/order`,
            { gateway_order },
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        acquirerOrderMessage.value = 'Preferências de adquirente salvas. O primeiro de cada lista é tentado primeiro na cobrança; os demais servem como redundância.';
        acquirerOrderError.value = false;
        router.reload({ only: ['gateways', 'gateway_order'] });
    } catch (err) {
        acquirerOrderError.value = true;
        acquirerOrderMessage.value =
            err.response?.data?.message || 'Não foi possível salvar a ordem dos adquirentes.';
    } finally {
        savingAcquirerOrder.value = false;
    }
}

const showPixAutoRow = computed(() =>
    (props.gateways || []).some((g) => g.is_connected && Array.isArray(g.methods) && g.methods.includes('pix_auto'))
);

const payoutPref = ref('auto');
const savingPayoutPref = ref(false);
const payoutPrefMessage = ref(null);
const payoutPrefError = ref(false);

watch(
    () => props.payout_gateway_preference,
    (v) => {
        payoutPref.value =
            v === 'cajupay' || v === 'spacepag' || v === 'woovi' || v === 'onlyup' ? v : 'auto';
    },
    { immediate: true }
);

function gatewayDisplayName(slug) {
    if (!slug) return '—';
    const g = (props.gateways || []).find((x) => x.slug === slug);
    return g?.name || slug;
}

const payoutFallbackActive = computed(() => {
    const p = payoutPref.value;
    const a = props.payout_gateway_active || null;
    if (p === 'auto' || !a) return false;
    return p !== a;
});

async function savePayoutPreference() {
    savingPayoutPref.value = true;
    payoutPrefMessage.value = null;
    payoutPrefError.value = false;
    try {
        const { data } = await axios.put(
            '/plataforma/financeiro/payout-gateway',
            { preference: payoutPref.value },
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        payoutPrefMessage.value = data?.message || 'Preferência salva.';
        payoutPrefError.value = false;
        router.reload({
            only: ['payout_gateway_preference', 'payout_gateway_active', 'gateways'],
        });
    } catch (err) {
        payoutPrefError.value = true;
        payoutPrefMessage.value =
            err.response?.data?.message || 'Não foi possível salvar a preferência de saque.';
    } finally {
        savingPayoutPref.value = false;
    }
}

function allAllowedTabIds() {
    return ['adquirentes', 'metodos', 'taxas', 'liquidacao'];
}

const activeTab = ref('adquirentes');
if (typeof window !== 'undefined') {
    const t = new URLSearchParams(window.location.search).get('tab');
    if (t && allAllowedTabIds().includes(t)) activeTab.value = t;
}

const tabs = computed(() => [
    { id: 'adquirentes', label: 'Adquirentes', icon: CreditCard },
    { id: 'metodos', label: 'Formas de pagamento', icon: LayoutGrid },
    { id: 'taxas', label: 'Taxas', icon: Percent },
    { id: 'liquidacao', label: 'Liquidação', icon: CalendarClock },
]);

const paymentMethodsForm = useForm({
    platform_payment_methods_enabled: { ...props.platform_payment_methods_enabled },
});

watch(
    () => props.platform_payment_methods_enabled,
    (v) => {
        paymentMethodsForm.platform_payment_methods_enabled = { ...v };
    },
    { deep: true }
);

function togglePlatformPaymentMethod(key) {
    const cur = paymentMethodsForm.platform_payment_methods_enabled[key];
    paymentMethodsForm.platform_payment_methods_enabled[key] = cur === false ? true : false;
}

function submitPaymentMethods() {
    paymentMethodsForm.put('/plataforma/financeiro/metodos-pagamento', {
        preserveScroll: true,
        onSuccess: () => paymentMethodsForm.clearErrors(),
    });
}

const gatewaySidebarOpen = ref(false);
const selectedGatewaySlug = ref(null);

function openGatewaySidebar(slug) {
    selectedGatewaySlug.value = slug;
    gatewaySidebarOpen.value = true;
}

function closeGatewaySidebar() {
    gatewaySidebarOpen.value = false;
    selectedGatewaySlug.value = null;
}

function onGatewaySaved() {
    router.reload({
        only: ['gateways', 'gateway_order', 'payout_gateway_preference', 'payout_gateway_active'],
    });
}

function feeBlock(key) {
    const r = props.merchant_fee_rules?.[key] || {};
    const percentRaw = r.percent ?? 0;
    return {
        percent: formatPercentForInput(percentRaw) || '0',
        fixed: r.fixed ?? 0,
    };
}

function buildFeeRulesFromProps() {
    const out = {};
    for (const row of feeMethodRows) {
        out[row.key] = feeBlock(row.key);
    }
    return out;
}

const feePercentRefs = {};
const feeFixedRefs = {};

function setFeePercentRef(key, el) {
    if (el) {
        feePercentRefs[key] = el;
    } else {
        delete feePercentRefs[key];
    }
}

function setFeeFixedRef(key, el) {
    if (el) {
        feeFixedRefs[key] = el;
    } else {
        delete feeFixedRefs[key];
    }
}

function flushFeeInputs() {
    for (const row of feeMethodRows) {
        feePercentRefs[row.key]?.commit?.();
        feeFixedRefs[row.key]?.commit?.();
    }
}

function updateFeeField(key, field, value) {
    feeForm.merchant_fee_rules = {
        ...feeForm.merchant_fee_rules,
        [key]: {
            ...feeForm.merchant_fee_rules[key],
            [field]: value,
        },
    };
}

const feeForm = useForm({
    merchant_fee_rules: {
        pix: feeBlock('pix'),
        api_pix: feeBlock('api_pix'),
        card: feeBlock('card'),
        apple_pay: feeBlock('apple_pay'),
        google_pay: feeBlock('google_pay'),
        boleto: feeBlock('boleto'),
        withdrawal: feeBlock('withdrawal'),
    },
    api_pix_enabled: props.api_pix_enabled,
});

function submitFees() {
    flushFeeInputs();
    feeForm
        .transform((data) => ({
            ...data,
            merchant_fee_rules: normalizeMerchantFeeRulesForSubmit(data.merchant_fee_rules),
        }))
        .put('/plataforma/financeiro/taxas', {
            preserveScroll: true,
            onSuccess: async () => {
                feeForm.clearErrors();
                await nextTick();
                feeForm.defaults({
                    merchant_fee_rules: buildFeeRulesFromProps(),
                    api_pix_enabled: props.api_pix_enabled,
                });
                feeForm.reset();
            },
        });
}

function settlementBlock(key) {
    const r = props.merchant_settlement_rules?.[key] || {};
    return {
        days_to_available: r.days_to_available ?? 0,
        reserve_percent: r.reserve_percent ?? 0,
        reserve_hold_days: r.reserve_hold_days ?? 0,
    };
}

/** Linhas da aba Liquidação (D+N / reserva por canal). */
const settlementMethodRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' },
    { key: 'boleto', label: 'Boleto' },
];

const settlementForm = useForm({
    merchant_settlement_rules: Object.fromEntries(
        settlementMethodRows.map(({ key }) => [key, settlementBlock(key)])
    ),
});

function submitSettlement() {
    settlementForm.put('/plataforma/financeiro/liquidacao', {
        preserveScroll: true,
        onSuccess: () => settlementForm.clearErrors(),
    });
}

</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Financeiro</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Pagamentos, adquirentes e taxas padrão. Pedidos em Transações; saques em Saques.
            </p>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>
        <p
            v-if="props.gateway_webhook_security_warnings?.length"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Segurança: configure o <strong>Webhook Secret</strong> nos gateways conectados
            ({{ props.gateway_webhook_security_warnings.join(', ') }}) — sem isso, notificações de pagamento são rejeitadas.
        </p>

        <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
            <nav
                class="inline-flex w-max rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
                aria-label="Abas de Financeiro"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                    :class="[
                        'flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                        activeTab === tab.id
                            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                </button>
            </nav>
        </div>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'adquirentes'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Adquirentes de pagamento
                    </h2>
                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Configure os adquirentes usados no checkout e nas APIs. Na cobrança, credenciais globais
                        (definidas aqui) têm prioridade sobre credenciais antigas por tenant.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <GatewayCard
                            v-for="g in props.gateways"
                            :key="g.slug"
                            :gateway="g"
                            @click="openGatewaySidebar(g.slug)"
                        />
                    </div>
                    <div
                        v-if="props.gateways.length === 0"
                        class="rounded-xl border border-dashed border-zinc-300 py-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400"
                    >
                        Nenhum adquirente disponível.
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-900 dark:ring-white/5"
                >
                    <div
                        class="border-b border-zinc-100 bg-gradient-to-br from-zinc-50 via-white to-[var(--color-primary)]/[0.06] px-5 py-5 sm:px-6 dark:border-zinc-700/80 dark:from-zinc-900 dark:via-zinc-900 dark:to-[var(--color-primary)]/[0.08]"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--color-primary)]/12 text-[var(--color-primary)] shadow-inner dark:bg-[var(--color-primary)]/20"
                                >
                                    <LayoutGrid class="h-6 w-6" stroke-width="1.75" aria-hidden="true" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">
                                        Prioridade por método
                                    </h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                        Defina qual adquirente entra primeiro em cada forma de pagamento. Os demais
                                        conectados ficam como redundância automática se o principal falhar.
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center rounded-full border border-zinc-200/80 bg-white/80 px-3 py-1 text-xs font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-600 dark:bg-zinc-800/80 dark:text-zinc-300"
                            >
                                Checkout &amp; API
                            </span>
                        </div>
                    </div>

                    <div class="space-y-5 p-5 sm:p-6">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <!-- PIX -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-emerald-500/25 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-emerald-500/20"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/12 text-emerald-600 ring-1 ring-emerald-500/20 dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/15"
                                        >
                                            <QrCode class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">PIX à vista</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Principal na cobrança</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-pix">Adquirente principal PIX</label>
                                <select
                                    id="acq-primary-pix"
                                    v-model="primaryPix"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none ring-zinc-950/5 transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-white/5"
                                    :disabled="connectedGatewaysForMethod('pix').length === 0"
                                >
                                    <option v-if="connectedGatewaysForMethod('pix').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="g in connectedGatewaysForMethod('pix')"
                                        :key="g.slug"
                                        :value="g.slug"
                                    >
                                        {{ g.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="connectedGatewaysForMethod('pix').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com PIX nos cartões acima para habilitar.
                                </p>
                            </div>

                            <!-- Cartão -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-indigo-500/25 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-indigo-500/20"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-500/12 text-indigo-600 ring-1 ring-indigo-500/20 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/15"
                                        >
                                            <CreditCard class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Cartão</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Crédito ou débito</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-card">Adquirente principal cartão</label>
                                <select
                                    id="acq-primary-card"
                                    v-model="primaryCard"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                    :disabled="connectedGatewaysForMethod('card').length === 0"
                                >
                                    <option v-if="connectedGatewaysForMethod('card').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="g in connectedGatewaysForMethod('card')"
                                        :key="g.slug"
                                        :value="g.slug"
                                    >
                                        {{ g.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="connectedGatewaysForMethod('card').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com cartão nos cartões acima.
                                </p>
                            </div>

                            <!-- Boleto -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-amber-500/30 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-amber-500/25 sm:col-span-2 xl:col-span-1"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/12 text-amber-700 ring-1 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/15"
                                        >
                                            <Barcode class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Boleto</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Pagamento em banco</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-boleto">Adquirente principal boleto</label>
                                <select
                                    id="acq-primary-boleto"
                                    v-model="primaryBoleto"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                    :disabled="connectedGatewaysForMethod('boleto').length === 0"
                                >
                                    <option v-if="connectedGatewaysForMethod('boleto').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="g in connectedGatewaysForMethod('boleto')"
                                        :key="g.slug"
                                        :value="g.slug"
                                    >
                                        {{ g.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="connectedGatewaysForMethod('boleto').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com boleto nos cartões acima.
                                </p>
                            </div>
                        </div>

                        <!-- PIX recorrente -->
                        <div
                            v-if="showPixAutoRow"
                            class="rounded-2xl border border-dashed border-violet-300/60 bg-gradient-to-r from-violet-50/80 via-white to-fuchsia-50/40 p-4 dark:border-violet-500/25 dark:from-violet-950/30 dark:via-zinc-900/50 dark:to-fuchsia-950/20 sm:p-5"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <span
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-500/15 text-violet-700 ring-1 ring-violet-500/25 dark:bg-violet-400/10 dark:text-violet-300 dark:ring-violet-400/20"
                                    >
                                        <Repeat class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            PIX recorrente · Assinaturas
                                        </p>
                                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                                            Cobrança automática de mensalidades (quando o adquirente suportar).
                                        </p>
                                    </div>
                                </div>
                                <div class="w-full shrink-0 sm:max-w-xs">
                                    <label class="sr-only" for="acq-primary-pix-auto">Adquirente PIX recorrente</label>
                                    <select
                                        id="acq-primary-pix-auto"
                                        v-model="primaryPixAuto"
                                        class="w-full cursor-pointer rounded-xl border border-violet-200/80 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-violet-500/30 dark:bg-zinc-900 dark:text-zinc-100"
                                        :disabled="connectedGatewaysForMethod('pix_auto').length === 0"
                                    >
                                        <option v-if="connectedGatewaysForMethod('pix_auto').length === 0" value="" disabled>
                                            Nenhum conectado
                                        </option>
                                        <option
                                            v-for="g in connectedGatewaysForMethod('pix_auto')"
                                            :key="g.slug"
                                            :value="g.slug"
                                        >
                                            {{ g.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <p
                                v-if="connectedGatewaysForMethod('pix_auto').length === 0"
                                class="mt-3 text-xs text-zinc-600 dark:text-zinc-400"
                            >
                                Conecte um adquirente com PIX automático (ex.: Efí) para assinaturas.
                            </p>
                        </div>

                        <div
                            class="flex flex-col gap-4 border-t border-zinc-100 pt-5 dark:border-zinc-700/80 sm:flex-row sm:items-start sm:gap-6"
                        >
                            <p
                                v-if="acquirerOrderMessage"
                                class="flex-1 rounded-xl px-4 py-3 text-sm leading-relaxed"
                                :class="
                                    acquirerOrderError
                                        ? 'bg-red-50 text-red-800 ring-1 ring-red-200/80 dark:bg-red-950/35 dark:text-red-200 dark:ring-red-900/50'
                                        : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200/80 dark:bg-emerald-950/35 dark:text-emerald-200 dark:ring-emerald-900/40'
                                "
                            >
                                {{ acquirerOrderMessage }}
                            </p>
                            <div class="flex shrink-0 justify-end sm:ml-auto">
                                <Button type="button" :disabled="savingAcquirerOrder" @click="saveAcquirerOrder">
                                    {{ savingAcquirerOrder ? 'Salvando...' : 'Salvar preferências' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-900 dark:ring-white/5"
                >
                    <div
                        class="border-b border-zinc-100 bg-gradient-to-br from-amber-50/90 via-white to-teal-50/40 px-5 py-5 sm:px-6 dark:border-zinc-700/80 dark:from-amber-950/20 dark:via-zinc-900 dark:to-teal-950/15"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-500/15 text-amber-700 ring-1 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20"
                                >
                                    <Banknote class="h-6 w-6" stroke-width="1.75" aria-hidden="true" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">
                                        Saque automático (cashout PIX)
                                    </h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">CajuPay</strong>,
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Spacepag</strong> e
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Woovi</strong> podem ser
                                        usados para saque automático PIX. Em modo automático a ordem é CajuPay → Spacepag →
                                        Woovi (o primeiro conectado vence).
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                            <span
                                class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-1 font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                            >
                                Em uso agora: {{ gatewayDisplayName(payout_gateway_active) }}
                            </span>
                            <span v-if="payoutFallbackActive" class="text-amber-800 dark:text-amber-200">
                                (preferido indisponível — usando fallback)
                            </span>
                        </div>
                        <div class="max-w-md">
                            <label class="mb-1.5 block text-sm font-medium text-zinc-800 dark:text-zinc-200" for="payout-pref-select">
                                Preferência
                            </label>
                            <select
                                id="payout-pref-select"
                                v-model="payoutPref"
                                class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                            >
                                <option value="auto">Automático (CajuPay → Spacepag → Woovi)</option>
                                <option value="cajupay">Forçar CajuPay</option>
                                <option value="spacepag">Forçar Spacepag</option>
                                <option value="woovi">Forçar Woovi</option>
                                <option value="onlyup">Forçar OnlyUp</option>
                            </select>
                        </div>
                        <p
                            v-if="payoutPrefMessage"
                            class="rounded-xl px-4 py-3 text-sm leading-relaxed"
                            :class="
                                payoutPrefError
                                    ? 'bg-red-50 text-red-800 ring-1 ring-red-200/80 dark:bg-red-950/35 dark:text-red-200'
                                    : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200/80 dark:bg-emerald-950/35 dark:text-emerald-200'
                            "
                        >
                            {{ payoutPrefMessage }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="button" :disabled="savingPayoutPref" @click="savePayoutPreference">
                                {{ savingPayoutPref ? 'Salvando...' : 'Salvar preferência de saque' }}
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'metodos'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Formas de pagamento na plataforma
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Ative ou desative cada forma de pagamento para <strong class="font-medium text-zinc-800 dark:text-zinc-200">toda a plataforma</strong>.
                        Infoprodutores ainda podem restringir por produto no checkout. Apple Pay e Google Pay exigem CajuPay como adquirente de cartão.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="m in props.platform_payment_method_labels"
                            :key="m.key"
                            type="button"
                            class="flex flex-col rounded-xl border-2 px-4 py-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                            :class="
                                paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/[0.08] dark:bg-[var(--color-primary)]/15'
                                    : 'border-zinc-200 bg-zinc-50/50 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800/40'
                            "
                            @click="togglePlatformPaymentMethod(m.key)"
                        >
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ m.label }}</span>
                            <span class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ m.hint }}</span>
                            <span
                                class="mt-3 inline-flex w-fit rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                :class="
                                    paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                        : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'
                                "
                            >
                                {{
                                    paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                        ? 'Ativo na plataforma'
                                        : 'Desativado'
                                }}
                            </span>
                        </button>
                    </div>
                    <p
                        v-if="paymentMethodsForm.errors.platform_payment_methods_enabled"
                        class="mt-4 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ paymentMethodsForm.errors.platform_payment_methods_enabled }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <Button
                            type="button"
                            :disabled="paymentMethodsForm.processing"
                            @click="submitPaymentMethods"
                        >
                            Salvar formas de pagamento
                        </Button>
                    </div>
                </section>
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'taxas'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Taxas padrão (plataforma)
                    </h2>
                    <p class="mb-2 text-sm text-zinc-600 dark:text-zinc-400">
                        Percentual e valor fixo por transação. <strong class="font-medium text-zinc-800 dark:text-zinc-200">PIX / cartão / Apple Pay / Google Pay / boleto</strong> valem para o checkout próprio da plataforma
                        (Apple Pay e Google Pay via CajuPay SDK usam as taxas próprias; se não configuradas, herdam a taxa de <strong class="font-medium">cartão</strong> até você definir valores distintos).
                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">API — PIX</strong> aplica-se só ao PIX criado pela API REST ou pelo link de checkout hospedado gerado pela API (cartão e boleto usam sempre as taxas de checkout).
                        Cada infoprodutor pode sobrescrever em Infoprodutores → editar.
                    </p>
                    <p class="mb-6 text-xs text-zinc-500 dark:text-zinc-400">
                        <strong>Percentual:</strong> valor de 0 a 100 (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">2,50</code> = 2,5% sobre o bruto).
                        <strong class="ml-2">Fixo:</strong> valor em <em>reais</em>, não centavos (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">1,50</code> = R$ 1,50 por transação).
                    </p>
                    <form class="space-y-6" @submit.prevent="submitFees">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[520px] text-left text-sm">
                                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                                    <tr>
                                        <th class="pb-2 pr-4">Canal</th>
                                        <th class="pb-2 pr-4">Percentual (%)</th>
                                        <th class="pb-2">Valor fixo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    <tr v-for="row in feeMethodRows" :key="'fee-' + row.key">
                                        <td class="py-3 font-medium text-zinc-900 dark:text-white">{{ row.label }}</td>
                                        <td class="py-3 pr-4">
                                            <FeePercentInput
                                                :ref="(el) => setFeePercentRef(row.key, el)"
                                                :model-value="feeForm.merchant_fee_rules[row.key].percent"
                                                @update:model-value="(v) => updateFeeField(row.key, 'percent', v)"
                                            />
                                        </td>
                                        <td class="py-3">
                                            <FeeFixedInput
                                                :ref="(el) => setFeeFixedRef(row.key, el)"
                                                :model-value="feeForm.merchant_fee_rules[row.key].fixed"
                                                @update:model-value="(v) => updateFeeField(row.key, 'fixed', v)"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="feeForm.errors.merchant_fee_rules" class="text-sm text-red-600">
                            {{ feeForm.errors.merchant_fee_rules }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="feeForm.processing">Salvar taxas</Button>
                        </div>
                        <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                            <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <input v-model="feeForm.api_pix_enabled" type="checkbox" class="h-4 w-4 rounded border-zinc-300" />
                                API PIX externa habilitada globalmente
                            </label>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pode ser sobrescrita por infoprodutor na tela API Pagamentos.</p>
                        </div>
                    </form>
                </section>
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'liquidacao'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Liquidação e reserva
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        <strong>D+N</strong>: dias até o líquido principal ir para o saldo disponível.
                        <strong>Reserva (%)</strong>: parte do líquido retida no pendente.
                        <strong>Retenção extra da reserva</strong>: dias <em>adicionais</em> (somados ao D+N) só para a parcela de reserva; depois o comando
                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">settlement:release</code> (agendado) libera automaticamente para o saldo disponível.
                        Zero em tudo = crédito imediato na carteira disponível (sem reserva).
                    </p>
                    <form class="space-y-6" @submit.prevent="submitSettlement">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[720px] text-left text-sm">
                                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                                    <tr>
                                        <th class="pb-2 pr-4">Canal</th>
                                        <th class="pb-2 pr-4">Dias até disponível (D+N)</th>
                                        <th class="pb-2 pr-4">Reserva (%)</th>
                                        <th class="pb-2">Retenção extra reserva (dias)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    <tr v-for="row in settlementMethodRows" :key="'settle-' + row.key">
                                        <td class="py-3 font-medium text-zinc-900 dark:text-white">{{ row.label }}</td>
                                        <td class="py-3 pr-4">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].days_to_available"
                                                type="number"
                                                min="0"
                                                max="365"
                                                step="1"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                        <td class="py-3 pr-4">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].reserve_percent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                        <td class="py-3">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].reserve_hold_days"
                                                type="number"
                                                min="0"
                                                max="365"
                                                step="1"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="settlementForm.errors.merchant_settlement_rules" class="text-sm text-red-600">
                            {{ settlementForm.errors.merchant_settlement_rules }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="settlementForm.processing">Salvar liquidação</Button>
                        </div>
                    </form>
                </section>
            </div>
        </Transition>

        <GatewayConfigSidebar
            :open="gatewaySidebarOpen"
            :gateway-slug="selectedGatewaySlug"
            :api-base-path="GATEWAYS_API_BASE"
            @close="closeGatewaySidebar"
            @saved="onGatewaySaved"
        />
    </div>
</template>
