<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Building2, Check, Clock, Loader2 } from 'lucide-vue-next';
import confetti from 'canvas-confetti';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { trackCheckoutPurchase, trackCheckoutPurchaseBeacon } from '@/composables/useCheckoutPurchaseTracking';
import { navigateAfterCheckout } from '@/lib/checkoutRedirect.js';

const POLL_INTERVAL_MS = 2500;

defineOptions({ layout: null });

const conversionPixelsRef = ref(null);

const props = defineProps({
    token: { type: String, required: true },
    order_id: { type: Number, required: true },
    checkout_session_token: { type: String, default: '' },
    amount_formatted: { type: String, default: 'R$ 0,00' },
    product_name: { type: String, default: '' },
    checkout_slug: { type: String, default: '' },
    redirect_after_purchase: { type: String, default: null },
    customer_name: { type: String, default: null },
    customer_email: { type: String, default: null },
    customer_phone: { type: String, default: null },
    created_at: { type: Number, required: true },
    expiry_seconds: { type: Number, default: 1800 },
    amount: { type: Number, default: 0 },
    status: { type: String, default: 'pending' },
    conversion_pixels: { type: Object, default: () => ({}) },
});

const pollStatus = ref(props.status === 'completed' ? 'completed' : 'pending');
const confirmFeedback = ref('');
const confirmChecking = ref(false);
let pollInterval = null;
let timerInterval = null;
let purchaseTracked = false;

const endTime = computed(() => (props.created_at + props.expiry_seconds) * 1000);
const timeLeft = ref(props.expiry_seconds);

function updateTimer() {
    const now = Date.now();
    const left = Math.max(0, Math.floor((endTime.value - now) / 1000));
    timeLeft.value = left;
    if (left <= 0 && pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

const timerDisplay = computed(() => {
    const left = timeLeft.value;
    const m = Math.floor(left / 60);
    const s = left % 60;
    return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
});

async function onPaymentCompleted(redirectUrl) {
    if (!purchaseTracked) {
        purchaseTracked = true;
        await trackCheckoutPurchase({
            orderId: props.order_id,
            checkoutSessionToken: props.checkout_session_token || '',
            token: props.token,
            triggerType: 'open_finance',
            value: props.amount,
            currency: 'BRL',
            pixels: props.conversion_pixels || {},
            conversionPixelsApi: conversionPixelsRef.value,
            settleDelayMs: 500,
        });
    }
    const url = redirectUrl || props.redirect_after_purchase || '/area-membros';
    navigateAfterCheckout(url);
}

function onPageHideBeacon() {
    if (pollStatus.value !== 'completed' || purchaseTracked) return;
    purchaseTracked = true;
    trackCheckoutPurchaseBeacon({
        orderId: props.order_id,
        checkoutSessionToken: props.checkout_session_token || '',
        token: props.token,
        triggerType: 'open_finance',
        value: props.amount,
        currency: 'BRL',
    });
}

async function checkOrderStatus() {
    try {
        const { data } = await axios.get('/checkout/order-status', { params: { token: props.token } });
        if (data.status === 'completed') {
            pollStatus.value = 'completed';
            confirmFeedback.value = 'Pagamento aprovado! Redirecionando...';
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            confetti({ particleCount: 120, spread: 70, origin: { y: 0.6 } });
            await onPaymentCompleted(data.redirect_url);
        }
        return data;
    } catch {
        return { status: 'pending' };
    }
}

function onConfirmPayment() {
    confirmChecking.value = true;
    confirmFeedback.value = 'Verificando no banco...';
    checkOrderStatus().then((data) => {
        confirmChecking.value = false;
        if (data.status !== 'completed') {
            confirmFeedback.value = 'Ainda processando. Autorize no app do seu banco se ainda não concluiu.';
        }
    });
}

function backToCheckout() {
    if (props.checkout_slug) {
        router.visit(`/c/${props.checkout_slug}`);
    } else {
        router.visit('/');
    }
}

onMounted(() => {
    updateTimer();
    timerInterval = setInterval(updateTimer, 1000);
    pollInterval = setInterval(() => {
        if (pollStatus.value === 'completed') return;
        checkOrderStatus();
    }, POLL_INTERVAL_MS);
    if (typeof window !== 'undefined') {
        window.addEventListener('pagehide', onPageHideBeacon);
    }
    checkOrderStatus();
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (pollInterval) clearInterval(pollInterval);
    if (typeof window !== 'undefined') {
        window.removeEventListener('pagehide', onPageHideBeacon);
    }
});
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" />
    <Head>
        <title>Open Finance</title>
    </Head>
    <div class="min-h-screen bg-gray-100 px-4 py-6 sm:py-8 pb-12">
        <div class="mx-auto w-full max-w-md">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="flex justify-center pt-8 pb-2">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-700">
                        <Building2 class="h-8 w-8" />
                    </div>
                </div>
                <div class="px-5 sm:px-6 pb-6">
                    <h1 class="mb-1 text-center text-lg font-bold text-gray-900 sm:text-xl">
                        Open Finance — {{ amount_formatted }}
                    </h1>
                    <p class="mb-5 text-center text-xs text-gray-500 sm:text-sm">
                        Autorize o pagamento no app do seu banco. Esta página atualiza automaticamente quando a instituição confirmar.
                    </p>

                    <div
                        v-if="pollStatus !== 'completed'"
                        class="mb-4 flex items-center justify-center gap-2 rounded-xl bg-blue-50 px-4 py-3 text-sm font-medium text-blue-800"
                    >
                        <Loader2 class="h-4 w-4 animate-spin" />
                        Aguardando confirmação do banco…
                    </div>

                    <div class="mb-4 space-y-3">
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white py-3 font-semibold text-gray-900 transition-colors hover:bg-gray-50 disabled:opacity-70"
                            :disabled="confirmChecking || pollStatus === 'completed'"
                            @click="onConfirmPayment"
                        >
                            <Check class="h-4 w-4 text-green-600" />
                            {{ confirmChecking ? 'Verificando...' : 'Já autorizei — confirmar' }}
                        </button>
                        <p
                            v-if="confirmFeedback"
                            class="text-center text-xs text-gray-500"
                            :class="pollStatus === 'completed' ? 'text-green-600' : ''"
                        >
                            {{ confirmFeedback }}
                        </p>
                    </div>

                    <div class="mb-4 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-center gap-2">
                            <Clock class="h-4 w-4 text-gray-500" />
                            <span class="text-sm font-medium text-gray-700">{{ timerDisplay }}</span>
                        </div>
                    </div>

                    <p v-if="product_name" class="mb-4 text-center text-sm text-gray-600">
                        {{ product_name }}
                    </p>

                    <button
                        type="button"
                        class="w-full text-center text-sm font-medium text-gray-500 underline-offset-2 hover:underline"
                        @click="backToCheckout"
                    >
                        Voltar ao checkout
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
