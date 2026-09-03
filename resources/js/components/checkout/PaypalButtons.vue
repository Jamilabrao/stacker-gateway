<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    clientId: { type: String, required: true },
    currency: { type: String, default: 'BRL' },
    locale: { type: String, default: 'pt_BR' },
    disabled: { type: Boolean, default: false },
    buildPayload: { type: Function, required: true },
    getCsrfToken: { type: Function, required: true },
    fillHint: { type: String, default: 'Preencha seus dados acima para pagar com PayPal.' },
    loadingHint: { type: String, default: 'Carregando PayPal…' },
});

const emit = defineEmits(['approved', 'error']);

const hostRef = ref(null);
const loading = ref(true);
const errorMessage = ref('');
let buttonsInstance = null;
let loadedClientId = '';
let lastPlatformOrderId = null;

function paypalLocale(locale) {
    const raw = String(locale || 'pt_BR').replace('-', '_');
    const lower = raw.toLowerCase();
    if (lower.startsWith('en')) return 'en_US';
    if (lower.startsWith('es')) return 'es_ES';
    return 'pt_BR';
}

function sdkUrl(clientId, currency) {
    const params = new URLSearchParams({
        'client-id': clientId,
        currency: (currency || 'BRL').toUpperCase(),
        intent: 'capture',
        'disable-funding': 'card,credit,paylater,venmo',
        locale: paypalLocale(props.locale),
    });
    return `https://www.paypal.com/sdk/js?${params.toString()}`;
}

function loadSdk(clientId, currency) {
    return new Promise((resolve, reject) => {
        if (typeof window === 'undefined') {
            reject(new Error('PayPal indisponível.'));
            return;
        }
        if (window.paypal && loadedClientId === clientId) {
            resolve(window.paypal);
            return;
        }
        document.querySelectorAll('script[data-stacker-paypal-sdk]').forEach((el) => el.remove());
        delete window.paypal;
        const script = document.createElement('script');
        script.src = sdkUrl(clientId, currency);
        script.async = true;
        script.dataset.stackerPaypalSdk = '1';
        script.onload = () => {
            loadedClientId = clientId;
            if (!window.paypal) {
                reject(new Error('PayPal SDK não carregou.'));
                return;
            }
            resolve(window.paypal);
        };
        script.onerror = () => reject(new Error('Não foi possível carregar o PayPal.'));
        document.head.appendChild(script);
    });
}

async function renderButtons() {
    if (!hostRef.value || !props.clientId || props.disabled) {
        loading.value = false;
        return;
    }
    loading.value = true;
    errorMessage.value = '';
    try {
        const paypal = await loadSdk(props.clientId.trim(), props.currency);
        if (buttonsInstance && typeof buttonsInstance.close === 'function') {
            try { buttonsInstance.close(); } catch (_) {}
        }
        hostRef.value.innerHTML = '';
        buttonsInstance = paypal.Buttons({
            style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'paypal' },
            createOrder: async () => {
                const payload = props.buildPayload();
                const { data } = await axios.post('/checkout/paypal/create-order', payload, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': props.getCsrfToken(),
                    },
                    withCredentials: true,
                });
                const id = data?.id || data?.paypal_order_id;
                if (!id) {
                    throw new Error(data?.message || 'Não foi possível iniciar o PayPal.');
                }
                lastPlatformOrderId = data?.order_id || null;
                return id;
            },
            onApprove: async (data) => {
                const payload = props.buildPayload();
                const { data: result } = await axios.post('/checkout/paypal/capture', {
                    paypal_order_id: data.orderID,
                    order_id: lastPlatformOrderId || payload.order_id || undefined,
                }, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': props.getCsrfToken(),
                    },
                    withCredentials: true,
                });
                if (result?.success && result.redirect_url) {
                    emit('approved', result);
                    return;
                }
                if (result?.success) {
                    emit('approved', result);
                    return;
                }
                throw new Error(result?.message || 'Pagamento PayPal não concluído.');
            },
            onError: (err) => {
                const msg = err?.message || 'Não foi possível pagar com PayPal.';
                errorMessage.value = msg;
                emit('error', msg);
            },
            onCancel: () => {
                errorMessage.value = '';
            },
        });
        await buttonsInstance.render(hostRef.value);
    } catch (err) {
        const msg = err?.response?.data?.message || err?.message || 'PayPal indisponível.';
        errorMessage.value = typeof msg === 'string' ? msg : 'PayPal indisponível.';
        emit('error', errorMessage.value);
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.clientId, props.currency, props.locale, props.disabled],
    () => { void renderButtons(); }
);

onMounted(() => { void renderButtons(); });
onBeforeUnmount(() => {
    if (buttonsInstance && typeof buttonsInstance.close === 'function') {
        try { buttonsInstance.close(); } catch (_) {}
    }
});
</script>

<template>
    <div class="space-y-2">
        <p v-if="disabled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ fillHint }}
        </p>
        <p v-else-if="loading" class="text-sm text-gray-500">{{ loadingHint }}</p>
        <div v-show="!disabled" ref="hostRef" class="min-h-[3rem]" />
        <p v-if="errorMessage" class="text-sm font-medium text-red-600" role="alert">{{ errorMessage }}</p>
    </div>
</template>
