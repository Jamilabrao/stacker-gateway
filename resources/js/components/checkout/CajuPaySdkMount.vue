<script setup>
import { onBeforeUnmount, ref, watch, computed, defineExpose } from 'vue';
import { mountCajuPayCheckout, confirmCajuPayController, cajupayDefaultMethodFor, setCajuPayPayer } from '@/composables/useCajuPaySdk';

const props = defineProps({
    paymentMethod: { type: String, required: true },
    sessionToken: { type: String, default: '' },
    /** Base da API CajuPay (ex.: resposta `sdk_base_url` do checkout). */
    apiBaseUrl: { type: String, default: '' },
    initialPayer: { type: Object, default: () => ({}) },
    containerId: { type: String, default: 'cajupay-method' },
    /** Apple/Google Pay: chamado imediatamente antes do 1º `confirm()` do SDK. */
    beforeWalletPrime: { type: Function, default: null },
});

const error = ref('');
const controller = ref(null);
const mountedToken = ref('');
const cardFieldReady = ref(false);
const cardPrimingInFlight = ref(false);

const containerSelector = computed(() => `#${props.containerId}`);
const needsPriming = computed(() => ['card', 'apple_pay', 'google_pay'].includes(props.paymentMethod));
const isCardMethod = computed(() => props.paymentMethod === 'card');

function destroyController() {
    try {
        controller.value?.destroy?.();
    } catch (_) {
        // ignore
    }
    controller.value = null;
    mountedToken.value = '';
    cardFieldReady.value = false;
    cardPrimingInFlight.value = false;
    const el = typeof document !== 'undefined' ? document.querySelector(containerSelector.value) : null;
    if (el) {
        try {
            el.innerHTML = '';
        } catch (_) { /* ignore */ }
    }
}

async function tryMount() {
    if (!props.sessionToken) {
        if (controller.value) destroyController();

        return;
    }
    if (mountedToken.value === props.sessionToken) {
        return;
    }
    error.value = '';
    if (controller.value) destroyController();
    try {
        await new Promise((r) => { setTimeout(r, 0); });
        const base = (props.apiBaseUrl || '').trim() || undefined;
        controller.value = await mountCajuPayCheckout(containerSelector.value, {
            token: props.sessionToken,
            baseUrl: base,
            defaultMethod: cajupayDefaultMethodFor(props.paymentMethod),
            initialPayer: props.initialPayer,
            onStatus: (event) => {
                const phase = event?.phase || event?.status || '';
                if (phase === 'awaiting_card_details') {
                    cardFieldReady.value = true;
                }
            },
        });
        mountedToken.value = props.sessionToken;

        if (needsPriming.value) {
            await primeCardField();
        } else {
            cardFieldReady.value = true;
        }
    } catch (e) {
        error.value = e?.message || 'Não foi possível carregar o checkout CajuPay.';
        controller.value = null;
    }
}

async function primeCardField() {
    if (!controller.value || cardPrimingInFlight.value || cardFieldReady.value) return;
    cardPrimingInFlight.value = true;

    setCajuPayPayer(controller.value, {
        name: props.initialPayer?.name,
        email: props.initialPayer?.email,
        document: props.initialPayer?.document,
    });

    try {
        if (
            (props.paymentMethod === 'apple_pay' || props.paymentMethod === 'google_pay')
            && typeof props.beforeWalletPrime === 'function'
        ) {
            await props.beforeWalletPrime();
        }
        await controller.value.confirm();
        cardFieldReady.value = true;
        error.value = '';
    } catch (e) {
        const msg = (e?.message || e?.error || '').toString().toLowerCase();
        if (msg.includes('awaiting') || msg.includes('card_details')) {
            cardFieldReady.value = true;
            error.value = '';
        } else if (msg.includes('payer_name') || msg.includes('payer_email') || msg.includes('payer_document')) {
            error.value = 'Preencha seus dados acima para carregar o pagamento.';
        } else if (msg.includes('method_not_available') || msg.includes('confirm_unavailable_for_method')) {
            const label = props.paymentMethod === 'apple_pay' ? 'Apple Pay'
                : props.paymentMethod === 'google_pay' ? 'Google Pay'
                : 'Esse método';
            error.value = `${label} não está disponível para esta conta CajuPay no momento. Selecione outra forma de pagamento (ex.: Cartão).`;
        } else if (!cardFieldReady.value) {
            const label = isCardMethod.value
                ? 'cartão'
                : props.paymentMethod === 'apple_pay' ? 'Apple Pay'
                : props.paymentMethod === 'google_pay' ? 'Google Pay'
                : 'pagamento';
            error.value = e?.message || `Falha ao iniciar o ${label}.`;
        }
    } finally {
        cardPrimingInFlight.value = false;
    }
}

watch(() => props.sessionToken, () => { tryMount(); }, { immediate: true });
watch(() => props.paymentMethod, () => {
    if (props.sessionToken) {
        mountedToken.value = '';
        tryMount();
    }
});

watch(
    () => props.apiBaseUrl,
    () => {
        if (props.sessionToken) {
            mountedToken.value = '';
            tryMount();
        }
    }
);

let primeRetryTimer = null;
watch(
    () => props.initialPayer,
    (val) => {
        if (!needsPriming.value) return;
        if (!controller.value) return;
        if (cardFieldReady.value) return;
        const hasMinPayer = (val?.name || '').trim() !== '' && (val?.email || '').trim() !== '';
        if (!hasMinPayer) return;
        clearTimeout(primeRetryTimer);
        primeRetryTimer = setTimeout(() => { primeCardField(); }, 400);
    },
    { deep: true }
);

onBeforeUnmount(() => {
    clearTimeout(primeRetryTimer);
    destroyController();
});

async function confirm() {
    if (!controller.value) {
        throw new Error('CajuPay: aguarde o checkout terminar de carregar.');
    }
    if (needsPriming.value && !cardFieldReady.value) {
        const start = Date.now();
        while (!cardFieldReady.value && Date.now() - start < 8000) {
            await new Promise((r) => { setTimeout(r, 100); });
        }
        if (!cardFieldReady.value) {
            throw new Error('CajuPay: o método de pagamento ainda não está pronto. Aguarde 1-2 segundos e clique novamente.');
        }
    }

    return await confirmCajuPayController(controller.value);
}

function setPayer(payer) {
    if (!controller.value) return false;

    return setCajuPayPayer(controller.value, payer);
}

defineExpose({
    confirm,
    isReady: () => !!controller.value,
    setPayer,
    isCardFieldReady: () => cardFieldReady.value,
});
</script>

<template>
    <div class="space-y-2">
        <div :id="containerId" />
        <div v-if="error" class="text-xs text-red-600">{{ error }}</div>
    </div>
</template>
