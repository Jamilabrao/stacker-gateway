<script setup>
import { computed } from 'vue';
import CheckoutBanners from './CheckoutBanners.vue';
import CheckoutReviews from './CheckoutReviews.vue';
import CheckoutPurchaseSummary from './CheckoutPurchaseSummary.vue';

const props = defineProps({
    product: { type: Object, required: true },
    subscriptionPlan: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
    /** Desconto aplicado pelo cupom: { discount_amount, final_price } */
    appliedCoupon: { type: Object, default: null },
    /** Order bumps selecionados (array de { id, title, amount_brl }) */
    selectedOrderBumps: { type: Array, default: () => [] },
    /** Soma dos valores em BRL dos bumps selecionados */
    orderBumpsTotalBrl: { type: Number, default: 0 },
    shippingAmountBrl: { type: Number, default: 0 },
    requiresShipping: { type: Boolean, default: false },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    priceInCurrency: { type: Function, default: (v) => v },
    formatPrice: { type: Function, default: (v, c) => String(v) },
});

const appearance = computed(() => props.config?.appearance ?? {});
const primaryColor = computed(() => appearance.value.primary_color || '#7427F1');
const sideBanners = computed(() => appearance.value.side_banners ?? []);
const hasReviews = computed(() => (props.config?.reviews ?? []).length > 0);
</script>

<template>
    <aside
        class="w-full space-y-6 lg:sticky lg:top-8 lg:w-1/3 lg:self-start"
        :class="hasReviews ? '' : 'hidden lg:block'"
        data-checkout="sidebar"
        data-checkout-column="secondary"
    >
        <div class="hidden lg:block">
            <CheckoutPurchaseSummary
                :product="product"
                :subscription-plan="subscriptionPlan"
                :applied-coupon="appliedCoupon"
                :selected-order-bumps="selectedOrderBumps"
                :order-bumps-total-brl="orderBumpsTotalBrl"
                :requires-shipping="requiresShipping"
                :shipping-amount-brl="shippingAmountBrl"
                :t="t"
                :display-currency="displayCurrency"
                :price-in-currency="priceInCurrency"
                :format-price="formatPrice"
                :primary-color="primaryColor"
            />
        </div>
        <!-- Banners laterais: apenas no desktop (abaixo do resumo). No mobile aparecem no final da página. -->
        <div v-if="sideBanners.filter(Boolean).length" class="hidden lg:block" data-checkout="sidebar-banners-wrap">
            <CheckoutBanners
                :urls="sideBanners"
                placement="side"
                class-img="w-full h-auto object-cover rounded-2xl shadow-lg"
            />
        </div>
        <CheckoutReviews
            v-if="hasReviews"
            :reviews="config.reviews"
            :primary-color="primaryColor"
        />
    </aside>
</template>
