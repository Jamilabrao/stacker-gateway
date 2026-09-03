<script setup>
import { computed } from 'vue';
import { Receipt, ShieldCheck } from 'lucide-vue-next';

const INTERVAL_LABELS = {
    weekly: 'Semanal',
    monthly: 'Mensal',
    quarterly: 'Trimestral',
    semi_annual: 'Semestral',
    annual: 'Anual',
    lifetime: 'Vitalício',
};
function intervalLabel(interval) {
    return INTERVAL_LABELS[interval] || interval || '';
}

const props = defineProps({
    product: { type: Object, required: true },
    subscriptionPlan: { type: Object, default: null },
    appliedCoupon: { type: Object, default: null },
    selectedOrderBumps: { type: Array, default: () => [] },
    orderBumpsTotalBrl: { type: Number, default: 0 },
    shippingAmountBrl: { type: Number, default: 0 },
    requiresShipping: { type: Boolean, default: false },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    priceInCurrency: { type: Function, default: (v) => v },
    formatPrice: { type: Function, default: (v, c) => String(v) },
    primaryColor: { type: String, default: '#7427F1' },
    /** Compacto: para ficar acima do botão no mobile, sem card próprio. */
    compact: { type: Boolean, default: false },
});

const mainProductPriceBrl = computed(() => {
    const applied = props.appliedCoupon;
    if (applied != null && applied.final_price != null) return Number(applied.final_price);
    return Number(props.product?.price_brl ?? props.product?.price ?? 0);
});
const totalPriceBrl = computed(() => {
    const shipping = props.requiresShipping ? Number(props.shippingAmountBrl) || 0 : 0;
    return mainProductPriceBrl.value + (Number(props.orderBumpsTotalBrl) || 0) + shipping;
});
const totalPrice = computed(() => props.priceInCurrency(totalPriceBrl.value));
const discountAmountBrl = computed(() =>
    props.appliedCoupon?.discount_amount != null ? Number(props.appliedCoupon.discount_amount) : 0
);
const discountAmount = computed(() => props.priceInCurrency(discountAmountBrl.value));
const showProductOriginalPrice = computed(() => discountAmountBrl.value > 0);
const productPriceBrl = computed(() => Number(props.product?.price_brl ?? props.product?.price ?? 0));
const productPriceDisplay = computed(() => props.priceInCurrency(productPriceBrl.value));
</script>

<template>
    <div
        :class="
            compact
                ? 'rounded-xl border-2 border-gray-100 bg-gray-50/70 p-4'
                : 'overflow-hidden rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-7'
        "
        data-id="final_summary"
        :data-checkout="compact ? 'form-purchase-summary' : 'sidebar-summary-card'"
    >
        <div
            class="flex items-center gap-3"
            :class="compact ? 'pb-3' : 'pb-4'"
            data-checkout="sidebar-summary-header"
        >
            <span
                class="flex items-center justify-center rounded-xl bg-gray-100 text-gray-600"
                :class="compact ? 'h-8 w-8' : 'h-10 w-10'"
            >
                <Receipt :class="compact ? 'h-4 w-4' : 'h-5 w-5'" />
            </span>
            <h2 :class="compact ? 'text-base font-bold text-gray-900' : 'text-lg font-bold tracking-tight text-gray-900'">
                {{ t('checkout.summary_title') }}
            </h2>
        </div>
        <div class="space-y-3" data-checkout="sidebar-line-items">
            <div class="flex justify-between gap-3 text-sm">
                <span class="truncate font-medium text-gray-700">{{ product.name }}</span>
                <span class="shrink-0 font-semibold text-gray-900">
                    <span v-if="showProductOriginalPrice" class="mr-1 text-gray-400 line-through">{{ formatPrice(productPriceDisplay, displayCurrency) }}</span>
                    {{ formatPrice(priceInCurrency(mainProductPriceBrl), displayCurrency) }}<span v-if="subscriptionPlan?.interval" class="ml-1 text-xs font-medium text-gray-500">{{ intervalLabel(subscriptionPlan.interval) }}</span>
                </span>
            </div>
            <template v-for="bump in selectedOrderBumps" :key="bump.id">
                <div class="flex justify-between gap-3 text-sm">
                    <span class="truncate font-medium text-gray-600">+ {{ bump.title }}</span>
                    <span class="shrink-0 font-semibold text-gray-900">{{ formatPrice(priceInCurrency(bump.amount_brl), displayCurrency) }}</span>
                </div>
            </template>
            <div v-if="discountAmountBrl > 0" class="flex justify-between gap-3 text-sm text-emerald-600">
                <span class="font-medium">{{ t('checkout.discount_coupon') }}</span>
                <span class="font-semibold">-{{ formatPrice(discountAmount, displayCurrency) }}</span>
            </div>
            <div v-if="requiresShipping && shippingAmountBrl > 0" class="flex justify-between gap-3 text-sm">
                <span class="font-medium text-gray-600">Frete</span>
                <span class="shrink-0 font-semibold text-gray-900">{{ formatPrice(priceInCurrency(shippingAmountBrl), 'BRL') }}</span>
            </div>
        </div>
        <hr :class="compact ? 'my-3' : 'my-5'" class="border-0 border-t border-gray-100" />
        <div class="flex items-center justify-between" data-checkout="sidebar-total">
            <span :class="compact ? 'text-sm font-bold text-gray-900' : 'text-base font-bold text-gray-900'">
                {{ t('checkout.total_a_pagar') || t('checkout.total') }}
            </span>
            <span class="font-bold tracking-tight" :class="compact ? 'text-xl' : 'text-2xl'" :style="{ color: primaryColor }">
                {{ formatPrice(totalPrice, displayCurrency) }}<span v-if="subscriptionPlan?.interval" class="ml-1 align-baseline text-sm font-medium text-gray-500">{{ intervalLabel(subscriptionPlan.interval) }}</span>
            </span>
        </div>
        <div
            v-if="!compact"
            class="mt-5 flex items-center justify-center gap-2 rounded-xl bg-gray-50 py-3 text-sm font-medium text-gray-600"
            data-checkout="sidebar-trust-badge"
        >
            <ShieldCheck class="h-4 w-4 text-emerald-500" aria-hidden="true" />
            {{ t('checkout.secure_purchase') }}
        </div>
    </div>
</template>
