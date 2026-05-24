<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { sendPurchasePixelAck } from '@/composables/usePurchasePixelAck';

defineOptions({ layout: null });

const conversionPixelsRef = ref(null);
let purchaseFiredForLoad = false;

const props = defineProps({
    redirect_url: { type: String, default: '/' },
    redirect_label: { type: String, default: 'Acessar área de membros' },
    subtitle: { type: String, default: 'Seu pedido foi registrado. Acesse o conteúdo pelo link abaixo.' },
    show_button: { type: Boolean, default: true },
    conversion_pixels: { type: Object, default: () => ({}) },
    order_id: { type: Number, default: null },
    order_amount: { type: Number, default: 0 },
    checkout_session_token: { type: String, default: '' },
});

/**
 * Meta Pixel só expõe fbq após init em ConversionPixels (@ready).
 * onMounted no pai perdia o evento; alinhado ao fluxo PIX/Boleto e referência opensource.
 */
async function onConversionPixelsReady() {
    if (purchaseFiredForLoad) return;
    if (!props.order_id || !(Number(props.order_amount) > 0)) return;
    const api = conversionPixelsRef.value;
    if (!api?.firePurchaseReliable) return;
    purchaseFiredForLoad = true;

    sendPurchasePixelAck({
        orderId: props.order_id,
        checkoutSessionToken: props.checkout_session_token || '',
        triggerType: 'approved',
    });

    await api.firePurchaseReliable(
        props.order_amount,
        'BRL',
        String(props.order_id),
        false,
        'approved',
        350
    );
}
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" @ready="onConversionPixelsReady" />
    <Head>
        <title>Obrigado pela compra</title>
    </Head>
    <div class="min-h-screen flex flex-col items-center justify-center bg-zinc-50 px-4">
        <div class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                <CheckCircle2 class="h-8 w-8" />
            </div>
            <h1 class="mt-4 text-xl font-semibold text-zinc-900">
                Obrigado pela sua compra
            </h1>
            <p class="mt-2 text-sm text-zinc-600">
                {{ subtitle }}
            </p>
            <a
                v-if="show_button"
                :href="redirect_url"
                class="mt-6 inline-flex w-full justify-center rounded-xl bg-[var(--color-primary,#0ea5e9)] px-4 py-3 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-opacity"
            >
                {{ redirect_label }}
            </a>
        </div>
    </div>
</template>
